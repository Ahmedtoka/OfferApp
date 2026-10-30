<?php

namespace App\Services;

use App\Offer\OfferConstants;
use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;

/** Offer settings live in an app-owned shop metafield; save() only succeeds when it reads back equal. */
class OfferSettings
{
    private const SHOP_ID = 'query ShopId { shop { id } }';

    private const READ = 'query ReadSettings($namespace: String!, $key: String!) { shop { metafield(namespace: $namespace, key: $key) { value } } }';

    private const WRITE = 'mutation WriteSettings($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message } } }';

    public function __construct(private GraphqlClient $graphql, private AutomaticDiscountManager $discounts) {}

    /** @return array{enabled: bool, percent: int, eligibleTag: string, updated_at: ?string} */
    public function read(string $shop): array
    {
        $data = $this->graphql->query($shop, self::READ, [
            'namespace' => OfferConstants::get('metafieldNamespace'),
            'key' => OfferConstants::get('metafieldKey'),
        ]);
        $raw = $data['shop']['metafield']['value'] ?? null;
        $json = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($json)) {
            return ['enabled' => false, 'percent' => (int) OfferConstants::get('defaultPercent'), 'eligibleTag' => self::defaultTag(), 'updated_at' => null];
        }

        $tag = $json['eligibleTag'] ?? null;

        return [
            'enabled' => (bool) ($json['enabled'] ?? false),
            'percent' => (int) ($json['percent'] ?? OfferConstants::get('defaultPercent')),
            // Configs saved before the tag became editable have no eligibleTag: fall back to the default.
            'eligibleTag' => is_string($tag) && trim($tag) !== '' ? $tag : self::defaultTag(),
            'updated_at' => isset($json['updated_at']) ? (string) $json['updated_at'] : null,
        ];
    }

    /**
     * Writes the shop config, reads it back (all three fields must match), then makes sure the
     * automatic discount exists and carries the tag in its input-variables metafield.
     *
     * @return array{settings: array{enabled: bool, percent: int, eligibleTag: string, updated_at: ?string}, discount: array{id: string, status: string, adminUrl: string, title: string}}
     *
     * @throws ShopifyGraphqlException|SaveMismatch
     */
    public function save(string $shop, bool $enabled, int $percent, string $eligibleTag): array
    {
        $ownerId = $this->graphql->query($shop, self::SHOP_ID)['shop']['id']
            ?? throw new ShopifyGraphqlException('Could not resolve the shop id.');

        $value = ['enabled' => $enabled, 'percent' => $percent, 'eligibleTag' => $eligibleTag, 'updated_at' => now()->toIso8601String()];
        $data = $this->graphql->query($shop, self::WRITE, ['metafields' => [[
            'ownerId' => $ownerId,
            'namespace' => OfferConstants::get('metafieldNamespace'),
            'key' => OfferConstants::get('metafieldKey'),
            'type' => 'json',
            'value' => json_encode($value),
        ]]]);

        $errors = $data['metafieldsSet']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyGraphqlException(implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)));
        }

        $stored = $this->read($shop);
        if ($stored['enabled'] !== $enabled || $stored['percent'] !== $percent || $stored['eligibleTag'] !== $eligibleTag) {
            throw new SaveMismatch('read-back mismatch');
        }

        return ['settings' => $stored, 'discount' => $this->discounts->ensure($shop, $eligibleTag)];
    }

    public static function defaultTag(): string
    {
        return (string) OfferConstants::get('eligibleTag');
    }
}
