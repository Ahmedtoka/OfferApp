<?php

namespace App\Http\Controllers;

use App\Http\Requests\TestCartRequest;
use App\Offer\BogoCalculator;
use App\Offer\Unit;
use App\Services\OfferSettings;
use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyAccessDenied;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Http\JsonResponse;

/**
 * The Test page's sample cart: the PHP engine run with the saved percent and rule, for both methods.
 * Amounts are decimals in the shop currency.
 */
class TestCartController extends Controller
{
    private const MAX_CANDIDATES = 25;

    /** hasTag / inCollection are only asked for when the saved rule needs them. */
    private const LOOKUP = 'query TestCartVariants($ids: [ID!]!%s) { shop { currencyCode } nodes(ids: $ids) { ... on ProductVariant { id title price compareAtPrice product { title isGiftCard %s } } } }';

    private const CURRENCY = 'query TestCartCurrency { shop { currencyCode } }';

    public function __construct(private GraphqlClient $graphql, private OfferSettings $offerSettings) {}

    public function store(TestCartRequest $request): JsonResponse
    {
        $shop = (string) $request->attributes->get('shop');
        $lines = $request->lines();

        try {
            $settings = $this->offerSettings->read($shop);
            $found = $this->lookup($shop, $settings, array_values(array_filter(array_column($lines, 'variantId'))));
        } catch (ShopifyAccessDenied) {
            return response()->json(['error' => 'needs_read_products'], 403);
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $currency = $found['currency'];
        $decimals = BogoCalculator::decimalsFor($currency);

        $titles = [];
        $eligible = [];
        $ineligible = [];
        foreach ($lines as $i => $line) {
            if ($line['variantId'] === null) {
                $titles[(string) $i] = (string) $line['title'];
                $now = BogoCalculator::toMinor((string) $line['price'], $decimals);
                $full = $line['compareAt'] === null ? $now : max($now, BogoCalculator::toMinor($line['compareAt'], $decimals));
                $eligible[] = ['id' => (string) $i, 'quantity' => $line['quantity'], 'pNow' => $now, 'pFull' => $full];

                continue;
            }

            $node = $found['nodes'][$line['variantId']] ?? null;
            $reason = $this->reason($node, $settings);
            $title = $node === null ? $line['variantId'] : $this->variantTitle($node);
            if ($reason !== null) {
                $ineligible[] = ['title' => $title, 'reason' => $reason];

                continue;
            }
            $titles[(string) $i] = $title;
            $now = BogoCalculator::toMinor((string) $node['price'], $decimals);
            $compare = $node['compareAtPrice'] ?? null;
            $full = is_string($compare) && $compare !== '' ? max($now, BogoCalculator::toMinor($compare, $decimals)) : $now;
            $eligible[] = ['id' => (string) $i, 'quantity' => $line['quantity'], 'pNow' => $now, 'pFull' => $full];
        }

        $units = BogoCalculator::expandUnits($eligible);

        return response()->json([
            'currency' => $currency,
            'percent' => $settings['percent'],
            'savedMethod' => $settings['method'],
            'customer' => $this->result($units, $settings['percent'], 'customer', $titles, $decimals),
            'company' => $this->result($units, $settings['percent'], 'company', $titles, $decimals),
            'ineligible' => $ineligible,
        ]);
    }

    /**
     * @param  list<string>  $ids
     * The shop currency is always read (typed prices need its decimals); variants only when there are ids.
     *
     * @return array{currency: string, nodes: array<string, array>}
     *
     * @throws ShopifyGraphqlException
     */
    private function lookup(string $shop, array $settings, array $ids): array
    {
        if ($ids === []) {
            return ['currency' => $this->currency($this->graphql->query($shop, self::CURRENCY)), 'nodes' => []];
        }
        $variables = ['ids' => array_values(array_unique($ids))];
        if ($settings['appliesTo'] === 'collection' && $settings['collectionId'] !== null) {
            $query = sprintf(self::LOOKUP, ', $collection: ID!', 'inCollection(id: $collection)');
            $variables['collection'] = $settings['collectionId'];
        } elseif ($settings['appliesTo'] === 'tag' && $settings['eligibleTag'] !== '') {
            $query = sprintf(self::LOOKUP, ', $tag: String!', 'hasTag(tag: $tag)');
            $variables['tag'] = $settings['eligibleTag'];
        } else {
            $query = sprintf(self::LOOKUP, '', '');
        }

        $data = $this->graphql->query($shop, $query, $variables);
        $nodes = [];
        foreach ((array) ($data['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $nodes[$node['id']] = $node;
            }
        }

        return ['currency' => $this->currency($data), 'nodes' => $nodes];
    }

    /** @throws ShopifyGraphqlException */
    private function currency(array $data): string
    {
        $code = $data['shop']['currencyCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : throw new ShopifyGraphqlException('Could not read the shop currency.');
    }

    private function reason(?array $node, array $settings): ?string
    {
        if ($node === null || ! isset($node['product'])) {
            return 'not_found';
        }
        if ($node['product']['isGiftCard'] ?? false) {
            return 'gift_card';
        }
        if ($settings['appliesTo'] === 'collection' && $settings['collectionId'] !== null) {
            return ($node['product']['inCollection'] ?? false) ? null : 'not_in_collection';
        }
        if ($settings['appliesTo'] === 'tag' && $settings['eligibleTag'] !== '') {
            return ($node['product']['hasTag'] ?? false) ? null : 'not_tagged';
        }

        return 'no_rule';
    }

    private function variantTitle(array $node): string
    {
        $product = (string) ($node['product']['title'] ?? '');
        $variant = (string) ($node['title'] ?? '');

        return $variant === '' || $variant === 'Default Title' ? $product : "{$product} - {$variant}";
    }

    /**
     * @param  array<int, Unit>  $units
     * @param  array<string, string>  $titles  line id => display title
     */
    private function result(array $units, int $percent, string $method, array $titles, int $decimals): array
    {
        $money = fn (int $minor): float => round($minor / (10 ** $decimals), $decimals);
        $result = BogoCalculator::compute($units, $percent, $method);
        $candidates = $units === [] ? [] : BogoCalculator::candidates($units, $percent, $method);
        $total = count($candidates);
        if ($total > self::MAX_CANDIDATES) {
            // Keep the chosen split visible even when it would fall past the cap.
            $chosen = array_values(array_filter($candidates, fn ($c) => $c['chosen']));
            $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES);
            if (! in_array(true, array_column($candidates, 'chosen'), true)) {
                // Swap the chosen one in for the last, keeping the list otherwise in order.
                $candidates[self::MAX_CANDIDATES - 1] = $chosen[0];
            }
        }

        return [
            'totalPay' => $money($result->totalPay),
            'totalDiscount' => $money($result->totalDiscount),
            'units' => array_map(fn (array $a) => [
                'title' => $titles[$a['unit']->lineId] ?? '',
                'role' => $a['role'],
                'price' => $money($a['unit']->pNow),
                'discount' => $money($a['discount']),
                'pays' => $money($a['unit']->pNow - $a['discount']),
            ], $result->assignments),
            'candidates' => array_map(fn (array $c) => [
                'single' => $c['single'] === null ? null : ($titles[$c['single']->lineId] ?? ''),
                'gifts' => array_map(fn (Unit $u) => $titles[$u->lineId] ?? '', $c['gifts']),
                'totalDiscount' => $money($c['totalDiscount']),
                'totalPay' => $money($c['totalPay']),
                'chosen' => $c['chosen'],
            ], $candidates),
            'candidatesTotal' => $total,
            'candidatesCapped' => $total > self::MAX_CANDIDATES,
        ];
    }
}
