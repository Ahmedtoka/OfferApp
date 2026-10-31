<?php

namespace App\Services;

use App\Offer\OfferConstants;
use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;

/**
 * Offer settings live in an app-owned shop metafield that the Function reads ({enabled, percent, method});
 * the page's own fields (appliesTo, eligibleTag, collectionId, combineWithCoupons) ride along in it.
 * The dates live on the discount. save() only succeeds when everything reads back equal.
 */
class OfferSettings
{
    /** The shop metafield's `disabled_reason` when the app turned the offer off because the subscription ended. */
    public const BILLING_ENDED = 'billing';

    private const SHOP_ID = 'query ShopId { shop { id } }';

    private const READ = 'query ReadSettings($namespace: String!, $key: String!) { shop { metafield(namespace: $namespace, key: $key) { value } } }';

    private const COLLECTION_TITLE = 'query CollectionTitle($id: ID!) { collection(id: $id) { title } }';

    private const WRITE = 'mutation WriteSettings($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message } } }';

    public function __construct(private GraphqlClient $graphql, private AutomaticDiscountManager $discounts) {}

    /** @return array{enabled: bool, percent: int, method: string, appliesTo: string, eligibleTag: string, collectionId: ?string, combineWithCoupons: bool, updated_at: ?string, disabledReason: ?string} */
    public function read(string $shop): array
    {
        $data = $this->graphql->query($shop, self::READ, [
            'namespace' => OfferConstants::get('metafieldNamespace'),
            'key' => OfferConstants::get('metafieldKey'),
        ]);
        $raw = $data['shop']['metafield']['value'] ?? null;
        $json = is_array($decoded = is_string($raw) ? json_decode($raw, true) : null) ? $decoded : [];

        $tag = $json['eligibleTag'] ?? null;
        $collection = $json['collectionId'] ?? null;
        $methods = (array) OfferConstants::get('methods');

        // Configs saved by older versions lack the newer fields: they read back as tag / customer / no coupons.
        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'percent' => (int) ($json['percent'] ?? OfferConstants::get('defaultPercent')),
            'method' => in_array($json['method'] ?? null, $methods, true) ? $json['method'] : (string) OfferConstants::get('defaultMethod'),
            'appliesTo' => ($json['appliesTo'] ?? null) === 'collection' ? 'collection' : 'tag',
            'eligibleTag' => is_string($tag) ? trim($tag) : '',
            'collectionId' => is_string($collection) && $collection !== '' ? $collection : null,
            'combineWithCoupons' => (bool) ($json['combineWithCoupons'] ?? false),
            'updated_at' => isset($json['updated_at']) ? (string) $json['updated_at'] : null,
            'disabledReason' => ($json['disabled_reason'] ?? null) === self::BILLING_ENDED ? self::BILLING_ENDED : null,
        ];
    }

    /**
     * The title of the collection the rule uses, or null when it no longer exists in the shop.
     *
     * @throws ShopifyGraphqlException
     */
    public function collectionTitle(string $shop, string $id): ?string
    {
        $title = $this->graphql->query($shop, self::COLLECTION_TITLE, ['id' => $id])['collection']['title'] ?? null;

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * Safe order: the discount (with its Function input metafield, read back) first, the shop metafield
     * last, then a read-back of it. If the last step fails the discount's rule is already new while the
     * shop settings are still old; that is reported as an error, never as success.
     *
     * @param  array{enabled: bool, percent: int, method: string, appliesTo: string, eligibleTag: string, collectionId: ?string, combineWithCoupons: bool, startsAt?: ?string, endsAt?: ?string}  $input
     * @return array{settings: array, discount: array{id: string, status: string, adminUrl: string, title: string, startsAt?: ?string, endsAt?: ?string}}
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function save(string $shop, array $input): array
    {
        $discount = $this->discounts->ensure($shop, $input);
        $previous = $discount['previous'];
        unset($discount['previous']);

        try {
            $stored = $this->writeShopSettings($shop, $input, null, $discount['startsAt'] ?? $input['startsAt'] ?? null, $discount['endsAt'] ?? $input['endsAt'] ?? null);
        } catch (ShopifyGraphqlException|SaveMismatch $e) {
            // The discount already carries the new rule while the page would read the old settings: put the old rule back.
            try {
                $this->discounts->restoreRule($shop, $discount['id'], $previous['tags'], $previous['collections']);
            } catch (ShopifyGraphqlException|SaveMismatch) {
                throw new ShopifyGraphqlException($e->getMessage().' The discount rule may be out of step with your settings; please Save again.');
            }

            throw $e;
        }

        return ['settings' => $stored, 'discount' => $discount];
    }

    /**
     * The subscription ended: switch the offer off in the shop metafield (the Function's switch) and say why.
     * Everything else in the settings is kept. Returns false when the offer was already off.
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function disableForBilling(string $shop): bool
    {
        $current = $this->read($shop);
        if (! $current['enabled']) {
            return false;
        }

        $this->writeShopSettings($shop, ['enabled' => false] + $current, self::BILLING_ENDED);

        return true;
    }

    /**
     * A shop that installs the app again starts with the offer off: the merchant switches it on when ready.
     * Only the shop metafield's `enabled` changes; the discount and every other setting stay as they are.
     * Returns false when there was nothing to switch off (no config, or already off).
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function disableForReinstall(string $shop): bool
    {
        $current = $this->read($shop);
        if (! $current['enabled']) {
            return false;
        }

        $this->writeShopSettings($shop, ['enabled' => false] + $current);

        return true;
    }

    /** @throws ShopifyGraphqlException|SaveMismatch */
    private function writeShopSettings(string $shop, array $input, ?string $disabledReason = null, ?string $startsAt = null, ?string $endsAt = null): array
    {
        $ownerId = $this->graphql->query($shop, self::SHOP_ID)['shop']['id']
            ?? throw new ShopifyGraphqlException('Could not resolve the shop id.');

        $value = [
            'enabled' => $input['enabled'],
            'percent' => $input['percent'],
            'method' => $input['method'],
            'appliesTo' => $input['appliesTo'],
            'eligibleTag' => $input['eligibleTag'],
            'collectionId' => $input['collectionId'],
            'combineWithCoupons' => $input['combineWithCoupons'],
            // For the storefront (Liquid cannot see the discount); the Function ignores them.
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'updated_at' => now()->toIso8601String(),
        ];
        if ($disabledReason !== null) {
            $value['disabled_reason'] = $disabledReason; // any other write drops it
        }
        $data = $this->graphql->query($shop, self::WRITE, ['metafields' => [[
            'ownerId' => $ownerId,
            'namespace' => OfferConstants::get('metafieldNamespace'),
            'key' => OfferConstants::get('metafieldKey'),
            'type' => 'json',
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]]);

        $errors = $data['metafieldsSet']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyGraphqlException(implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)));
        }

        $stored = $this->read($shop);
        foreach (['enabled', 'percent', 'method', 'appliesTo', 'eligibleTag', 'collectionId', 'combineWithCoupons'] as $field) {
            if ($stored[$field] !== $value[$field]) {
                throw new SaveMismatch('read-back mismatch');
            }
        }

        return $stored;
    }
}
