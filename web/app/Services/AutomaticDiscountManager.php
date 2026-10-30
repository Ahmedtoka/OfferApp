<?php

namespace App\Services;

use App\Offer\OfferConstants;
use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;

/** Finds / creates the single automatic discount that hosts the BOGO Function. */
class AutomaticDiscountManager
{
    private const FUNCTIONS = 'query AppFunctions { shopifyFunctions(first: 25) { nodes { id title apiType app { title } } } }';

    private const DISCOUNTS = 'query AppDiscounts { discountNodes(first: 50, query: "type:app") { nodes { id discount { __typename ... on DiscountAutomaticApp { title status appDiscountType { functionId } } } } } }';

    private const CREATE = 'mutation CreateDiscount($d: DiscountAutomaticAppInput!) { discountAutomaticAppCreate(automaticAppDiscount: $d) { automaticAppDiscount { discountId title status } userErrors { field message } } }';

    private const UPDATE = 'mutation UpdateDiscount($id: ID!, $d: DiscountAutomaticAppInput!) { discountAutomaticAppUpdate(id: $id, automaticAppDiscount: $d) { automaticAppDiscount { discountId title status } userErrors { field message } } }';

    private const ACTIVATE = 'mutation ActivateDiscount($id: ID!) { discountAutomaticActivate(id: $id) { automaticDiscountNode { id automaticDiscount { ... on DiscountAutomaticApp { status } } } userErrors { field message } } }';

    public function __construct(private GraphqlClient $graphql) {}

    /**
     * The existing discount (matched by our Function id) or null. Never creates.
     *
     * @return array{id: string, status: string, adminUrl: string, title: string}|null
     */
    public function find(string $shop): ?array
    {
        $functionId = $this->functionId($shop);

        return $functionId === null ? null : $this->findByFunction($shop, $functionId);
    }

    /**
     * Find or create the discount; reactivates it if it is not ACTIVE (expired, scheduled or
     * deactivated in Shopify admin) and renames it if its title drifted.
     *
     * @return array{id: string, status: string, adminUrl: string, title: string}
     *
     * @throws ShopifyGraphqlException
     */
    public function ensure(string $shop): array
    {
        $functionId = $this->functionId($shop)
            ?? throw new ShopifyGraphqlException('BOGO discount function was not found in this shop; deploy the app first.');
        $title = (string) OfferConstants::get('discountTitle');

        $existing = $this->findByFunction($shop, $functionId);
        if ($existing === null) {
            $data = $this->graphql->query($shop, self::CREATE, ['d' => [
                'title' => $title,
                'functionId' => $functionId,
                'startsAt' => now()->toIso8601String(),
                'discountClasses' => ['PRODUCT'],
                'combinesWith' => ['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false],
            ]]);

            return $this->fromMutation($shop, $data['discountAutomaticAppCreate'] ?? null);
        }

        if ($existing['status'] !== 'ACTIVE') {
            $existing = $this->activate($shop, $functionId, $existing);
        }

        if ($existing['title'] !== $title) {
            $data = $this->graphql->query($shop, self::UPDATE, ['id' => $existing['id'], 'd' => ['title' => $title]]);

            return $this->fromMutation($shop, $data['discountAutomaticAppUpdate'] ?? null);
        }

        return $existing;
    }

    /**
     * Reactivates the discount, then re-reads it; throws unless Shopify now reports it ACTIVE.
     *
     * @throws ShopifyGraphqlException
     */
    private function activate(string $shop, string $functionId, array $existing): array
    {
        $payload = $this->graphql->query($shop, self::ACTIVATE, ['id' => $existing['id']])['discountAutomaticActivate'] ?? null;
        $errors = $payload['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyGraphqlException(implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)));
        }

        $reread = $this->findByFunction($shop, $functionId);
        if ($reread === null || $reread['status'] !== 'ACTIVE') {
            $status = $reread['status'] ?? 'missing';
            throw new ShopifyGraphqlException("The automatic discount is {$status} in Shopify and could not be activated. Check it under Discounts in Shopify admin.");
        }

        return $reread;
    }

    private function functionId(string $shop): ?string
    {
        $data = $this->graphql->query($shop, self::FUNCTIONS);
        foreach ($data['shopifyFunctions']['nodes'] ?? [] as $fn) {
            if (($fn['apiType'] ?? null) === 'discount' && ($fn['title'] ?? null) === config('shopify.function_title')) {
                return (string) $fn['id'];
            }
        }

        return null;
    }

    private function findByFunction(string $shop, string $functionId): ?array
    {
        $data = $this->graphql->query($shop, self::DISCOUNTS);
        foreach ($data['discountNodes']['nodes'] ?? [] as $node) {
            $d = $node['discount'] ?? [];
            if (($d['__typename'] ?? null) === 'DiscountAutomaticApp'
                && ($d['appDiscountType']['functionId'] ?? null) === $functionId) {
                return $this->shape($shop, (string) $node['id'], (string) ($d['title'] ?? ''), (string) ($d['status'] ?? ''));
            }
        }

        return null;
    }

    private function fromMutation(string $shop, ?array $payload): array
    {
        $errors = $payload['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyGraphqlException(implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)));
        }
        $d = $payload['automaticAppDiscount'] ?? null;
        if (! is_array($d) || empty($d['discountId'])) {
            throw new ShopifyGraphqlException('Shopify did not return the automatic discount.');
        }

        return $this->shape($shop, (string) $d['discountId'], (string) ($d['title'] ?? ''), (string) ($d['status'] ?? ''));
    }

    private function shape(string $shop, string $gid, string $title, string $status): array
    {
        $handle = preg_replace('/\.myshopify\.com$/', '', $shop);
        $numeric = substr($gid, (int) strrpos($gid, '/') + 1);

        return [
            'id' => $gid,
            'status' => $status,
            'adminUrl' => "https://admin.shopify.com/store/{$handle}/discounts/{$numeric}",
            'title' => $title,
        ];
    }
}
