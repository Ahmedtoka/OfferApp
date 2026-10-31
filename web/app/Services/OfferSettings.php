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
    private const SHOP_ID = 'query ShopId { shop { id } }';

    private const READ = 'query ReadSettings($namespace: String!, $key: String!) { shop { metafield(namespace: $namespace, key: $key) { value } } }';

    private const WRITE = 'mutation WriteSettings($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message } } }';

    public function __construct(private GraphqlClient $graphql, private AutomaticDiscountManager $discounts) {}

    /** @return array{enabled: bool, percent: int, method: string, appliesTo: string, eligibleTag: string, collectionId: ?string, combineWithCoupons: bool, updated_at: ?string} */
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
        ];
    }

    /**
     * Safe order: the discount (with its Function input metafield, read back) first, the shop metafield
     * last, then a read-back of it. If the last step fails the discount's rule is already new while the
     * shop settings are still old; that is reported as an error, never as success.
     *
     * @param  array{enabled: bool, percent: int, method: string, appliesTo: string, eligibleTag: string, collectionId: ?string, combineWithCoupons: bool, startsAt: ?string, endsAt: ?string}  $input
     * @return array{settings: array, discount: array{id: string, status: string, adminUrl: string, title: string, startsAt: ?string, endsAt: ?string}}
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function save(string $shop, array $input): array
    {
        $discount = $this->discounts->ensure($shop, $input);

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
            'updated_at' => now()->toIso8601String(),
        ];
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

        return ['settings' => $stored, 'discount' => $discount];
    }
}
