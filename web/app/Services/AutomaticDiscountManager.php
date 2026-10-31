<?php

namespace App\Services;

use App\Offer\OfferConstants;
use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;

/** Finds / creates the single automatic discount that hosts the BOGO Function. */
class AutomaticDiscountManager
{
    private const FUNCTIONS = 'query AppFunctions { shopifyFunctions(first: 25) { nodes { id title apiType app { title } } } }';

    private const DISCOUNTS = 'query AppDiscounts { discountNodes(first: 50, query: "type:app") { nodes { id discount { __typename ... on DiscountAutomaticApp { title status startsAt endsAt appDiscountType { functionId } } } } } }';

    private const CREATE = 'mutation CreateDiscount($d: DiscountAutomaticAppInput!) { discountAutomaticAppCreate(automaticAppDiscount: $d) { automaticAppDiscount { discountId title status startsAt endsAt } userErrors { field message } } }';

    private const UPDATE = 'mutation UpdateDiscount($id: ID!, $d: DiscountAutomaticAppInput!) { discountAutomaticAppUpdate(id: $id, automaticAppDiscount: $d) { automaticAppDiscount { discountId title status startsAt endsAt } userErrors { field message } } }';

    /** Input query variables live on the discount node (Shopify reads them for $eligibleTags). */
    private const WRITE_CONFIG = 'mutation WriteDiscountConfig($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message } } }';

    private const READ_CONFIG = 'query ReadDiscountConfig($id: ID!, $namespace: String!, $key: String!) { discountNode(id: $id) { metafield(namespace: $namespace, key: $key) { value } } }';

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
     * Find or create the discount and make it carry the offer's rule: title, dates, coupon combination
     * and the Function input metafield $app:bogo/config {"eligibleTags": [...], "collectionIds": [...]}
     * (both arrays always present; verified by reading it back). Only an offer that is being switched
     * on reactivates a discount that is not ACTIVE (deactivated or expired in Shopify admin); an
     * offer saved as off leaves the discount's own state alone.
     *
     * Dates follow key presence: startsAt / endsAt are sent only when `$input` has the key. A null endsAt
     * (clear the end date) is sent only when the offer is being switched on, because an offer-off Save must
     * not wipe the end date Shopify set when the merchant deactivated the discount.
     *
     * The result carries `previous` (the rule the discount had before: empty arrays for a new discount) so a
     * caller can restore it with restoreRule().
     *
     * @param  array{enabled: bool, appliesTo: string, eligibleTag: string, collectionId: ?string, combineWithCoupons: bool, startsAt?: ?string, endsAt?: ?string}  $input
     * @return array{id: string, status: string, adminUrl: string, title: string, startsAt: ?string, endsAt: ?string, previous: array{tags: list<string>, collections: list<string>}}
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function ensure(string $shop, array $input): array
    {
        $tags = $input['appliesTo'] === 'tag' ? [$input['eligibleTag']] : [];
        $collections = $input['appliesTo'] === 'collection' ? [(string) $input['collectionId']] : [];
        $metafield = $this->metafield($tags, $collections);
        $coupons = $input['combineWithCoupons'];
        $combinesWith = ['orderDiscounts' => $coupons, 'productDiscounts' => $coupons, 'shippingDiscounts' => $coupons];

        $functionId = $this->functionId($shop)
            ?? throw new ShopifyGraphqlException('BOGO discount function was not found in this shop; deploy the app first.');
        $title = (string) OfferConstants::get('discountTitle');

        $existing = $this->findByFunction($shop, $functionId);
        if ($existing === null) {
            $data = $this->graphql->query($shop, self::CREATE, ['d' => [
                'title' => $title,
                'functionId' => $functionId,
                'startsAt' => $input['startsAt'] ?? now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'discountClasses' => ['PRODUCT'],
                'combinesWith' => $combinesWith,
                'metafields' => [$metafield],
            ] + (($input['endsAt'] ?? null) !== null ? ['endsAt' => $input['endsAt']] : [])]);

            $created = $this->fromMutation($shop, $data['discountAutomaticAppCreate'] ?? null);
            $this->verifyConfig($shop, $created['id'], $tags, $collections);

            return $created + ['previous' => ['tags' => [], 'collections' => []]];
        }

        $previous = $this->readConfig($shop, $existing['id']);

        // Activation clears the end date and restarts the discount, so it comes before the dates are written,
        // and is skipped when the merchant asked for an end date that has already passed.
        $endsAt = $input['endsAt'] ?? null;
        $ended = $endsAt !== null && \Carbon\CarbonImmutable::parse($endsAt)->lessThanOrEqualTo(now());
        if ($input['enabled'] && $existing['status'] === 'EXPIRED' && ! $ended) {
            $this->activate($shop, $functionId, $existing);
        }

        $update = ['title' => $title, 'combinesWith' => $combinesWith];
        if (($input['startsAt'] ?? null) !== null) {
            $update['startsAt'] = $input['startsAt'];
        }
        if (array_key_exists('endsAt', $input) && ($endsAt !== null || $input['enabled'])) {
            $update['endsAt'] = $endsAt;
        }
        $data = $this->graphql->query($shop, self::UPDATE, ['id' => $existing['id'], 'd' => $update]);
        $existing = $this->fromMutation($shop, $data['discountAutomaticAppUpdate'] ?? null);

        $this->writeConfig($shop, $existing['id'], $tags, $collections);

        return $existing + ['previous' => $previous];
    }

    /**
     * Puts a previous rule back on the discount (read back, like any rule write).
     *
     * @param  list<string>  $tags
     * @param  list<string>  $collections
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function restoreRule(string $shop, string $discountId, array $tags, array $collections): void
    {
        $this->writeConfig($shop, $discountId, $tags, $collections);
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $collections
     */
    private function writeConfig(string $shop, string $discountId, array $tags, array $collections): void
    {
        $data = $this->graphql->query($shop, self::WRITE_CONFIG, ['metafields' => [['ownerId' => $discountId] + $this->metafield($tags, $collections)]]);
        $errors = $data['metafieldsSet']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyGraphqlException(implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)));
        }
        $this->verifyConfig($shop, $discountId, $tags, $collections);
    }

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $collections
     */
    private function metafield(array $tags, array $collections): array
    {
        return [
            'namespace' => (string) OfferConstants::get('metafieldNamespace'),
            'key' => (string) OfferConstants::get('metafieldDiscountKey'),
            'type' => 'json',
            'value' => json_encode(['eligibleTags' => $tags, 'collectionIds' => $collections], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The rule currently on the discount; a missing or malformed metafield means "matches nothing".
     *
     * @return array{tags: list<string>, collections: list<string>}
     */
    private function readConfig(string $shop, string $discountId): array
    {
        $data = $this->graphql->query($shop, self::READ_CONFIG, [
            'id' => $discountId,
            'namespace' => (string) OfferConstants::get('metafieldNamespace'),
            'key' => (string) OfferConstants::get('metafieldDiscountKey'),
        ]);
        $raw = $data['discountNode']['metafield']['value'] ?? null;
        $json = is_string($raw) ? json_decode($raw, true) : null;
        $strings = fn ($v) => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        return ['tags' => $strings($json['eligibleTags'] ?? null), 'collections' => $strings($json['collectionIds'] ?? null)];
    }

    /**
     * Reads the discount's input-variables metafield back; anything other than exactly
     * {"eligibleTags": $tags, "collectionIds": $collections} is a mismatch (the Function would run with
     * the wrong eligibility, or fail on a missing required variable).
     *
     * @param  list<string>  $tags
     * @param  list<string>  $collections
     *
     * @throws SaveMismatch
     */
    private function verifyConfig(string $shop, string $discountId, array $tags, array $collections): void
    {
        $data = $this->graphql->query($shop, self::READ_CONFIG, [
            'id' => $discountId,
            'namespace' => (string) OfferConstants::get('metafieldNamespace'),
            'key' => (string) OfferConstants::get('metafieldDiscountKey'),
        ]);
        $raw = $data['discountNode']['metafield']['value'] ?? null;
        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($json) || ($json['eligibleTags'] ?? null) !== $tags || ($json['collectionIds'] ?? null) !== $collections) {
            throw new SaveMismatch('discount config read-back mismatch');
        }
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
                return $this->shape($shop, (string) $node['id'], (string) ($d['title'] ?? ''), (string) ($d['status'] ?? ''), $d['startsAt'] ?? null, $d['endsAt'] ?? null);
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

        return $this->shape($shop, (string) $d['discountId'], (string) ($d['title'] ?? ''), (string) ($d['status'] ?? ''), $d['startsAt'] ?? null, $d['endsAt'] ?? null);
    }

    private function shape(string $shop, string $gid, string $title, string $status, ?string $startsAt = null, ?string $endsAt = null): array
    {
        $handle = preg_replace('/\.myshopify\.com$/', '', $shop);
        $numeric = substr($gid, (int) strrpos($gid, '/') + 1);

        return [
            'id' => $gid,
            'status' => $status,
            'adminUrl' => "https://admin.shopify.com/store/{$handle}/discounts/{$numeric}",
            'title' => $title,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
        ];
    }
}
