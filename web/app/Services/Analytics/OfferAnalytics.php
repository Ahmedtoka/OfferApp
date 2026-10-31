<?php

namespace App\Services\Analytics;

use App\Offer\BogoCalculator;
use App\Offer\OfferConstants;
use App\Services\OfferSettings;
use App\Shopify\ShopifyAccessDenied;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Support\Facades\Cache;

/** Live offer numbers from Admin GraphQL orders. Internally everything is integer minor units of the shop currency. */
class OfferAnalytics
{
    /**
     * Allocation titles earlier versions emitted. Orders placed before still carry them, so analytics must keep
     * recognising them. Never emitted again (the current ones are in the shared constants).
     */
    private const LEGACY_DISCOUNT_TITLES = ['Buy One Get One', 'اشتري واحدة وخد واحدة'];

    private bool $fromMessages = true;

    /** Percent-message regexes (every language), built once per compute. */
    private ?array $percentRes = null;

    private string $method = 'customer';

    private string $appliesTo = 'tag';

    /** The merchant's eligible tag (shop config), set per compute. */
    private string $eligibleTag = '';

    private int $decimals = 2;

    public function __construct(private OrdersFetcher $fetcher, private OfferSettings $settings) {}

    /** false = ignore gift/percent titles and always derive unit counts by recomputing the cart. */
    public function classifyFromMessages(bool $on): static
    {
        $this->fromMessages = $on;

        return $this;
    }

    /**
     * @throws ShopifyAccessDenied when the app may not read orders
     * @throws ShopifyGraphqlException
     */
    public function compute(string $shop, int $rangeDays): AnalyticsResult
    {
        // One entry per shop and range, so erasing a shop can name every key. Changing the method or rule recomputes at once.
        $config = $this->settings->read($shop);
        $this->method = $config['method'];
        $this->appliesTo = $config['appliesTo'];
        $this->eligibleTag = $config['eligibleTag'];
        $rule = $this->appliesTo === 'collection' ? (string) $config['collectionId'] : $this->eligibleTag;
        $fingerprint = sha1("{$this->method}|{$this->appliesTo}|{$rule}");

        $entry = Cache::get(self::cacheKey($shop, $rangeDays));
        if (! is_array($entry) || ($entry['fingerprint'] ?? null) !== $fingerprint) {
            $result = $this->build($shop, $rangeDays, $this->appliesTo === 'collection' ? $config['collectionId'] : null)->toArray();
            $entry = ['fingerprint' => $fingerprint, 'result' => $result];
            Cache::put(self::cacheKey($shop, $rangeDays), $entry, (int) OfferConstants::get('analyticsCacheSeconds'));
        }

        return AnalyticsResult::fromArray($entry['result']);
    }

    public static function cacheKey(string $shop, int $rangeDays): string
    {
        return "analytics:{$shop}:{$rangeDays}";
    }

    /** Removes everything cached for the shop (the expired rows of the database cache included). */
    public static function forgetShop(string $shop): void
    {
        foreach ((array) OfferConstants::get('analyticsRanges') as $range) {
            Cache::forget(self::cacheKey($shop, (int) $range));
        }
    }

    private function build(string $shop, int $rangeDays, ?string $collectionId): AnalyticsResult
    {
        $this->percentRes = self::percentPatterns();
        $fetched = $this->fetcher->fetch($shop, $rangeDays, (int) OfferConstants::get('analyticsOrderCap'), $collectionId);
        $currency = $fetched['currency'];
        $this->decimals = BogoCalculator::decimalsFor($currency);
        $orders = array_map(fn (array $o) => $this->analyseOrder($o), $fetched['orders']);
        $rangePct = $this->rangePercent($orders);
        $otherMethod = $this->method === 'company' ? 'customer' : 'company';

        $offerOrders = $giftUnits = $percentUnits = $totalDiscount = $difference = 0;
        $offerSales = $offerGross = $otherSales = $unitsOffer = $unitsOther = 0;
        $gifts = [];
        $offerLines = [];
        foreach ($orders as $order) {
            if ($order['ours'] === []) {
                $otherSales += $order['net'];
                $unitsOther += $order['units'];

                continue;
            }
            $offerOrders++;
            $offerSales += $order['net'];
            $offerGross += $order['gross'];
            $unitsOffer += $order['units'];
            array_push($offerLines, ...$order['lines']);
            $totalDiscount += array_sum(array_column($order['ours'], 'amount'));
            $pct = $order['pct'] ?? $rangePct;

            $counts = ($this->fromMessages && $order['hasMessages'])
                ? $this->countsFromMessages($order)
                : $this->countsFromRecompute($order, $pct);
            $giftUnits += $counts['giftUnits'];
            $percentUnits += $counts['percentUnits'];
            foreach ($counts['gifts'] as $title => $g) {
                $gifts[$title]['units'] = ($gifts[$title]['units'] ?? 0) + $g['units'];
                $gifts[$title]['value'] = ($gifts[$title]['value'] ?? 0) + $g['value'];
            }

            $difference += $this->otherMethodDifference($order, $pct, $otherMethod);
        }

        $ordersTotal = count($orders);
        $otherOrders = $ordersTotal - $offerOrders;

        return new AnalyticsResult(
            currency: $currency,
            rangeDays: $rangeDays,
            ordersTotal: $ordersTotal,
            offerOrders: $offerOrders,
            offerOrdersPct: $ordersTotal === 0 ? 0.0 : round($offerOrders * 100 / $ordersTotal, 1),
            offerSales: $this->money($offerSales),
            totalDiscount: $this->money($totalDiscount),
            discountPctOfSales: $offerGross <= 0 ? 0.0 : round($totalDiscount * 100 / $offerGross, 1),
            aovOffer: $offerOrders === 0 ? null : $this->money((int) round($offerSales / $offerOrders)),
            aovOther: $otherOrders === 0 ? null : $this->money((int) round($otherSales / $otherOrders)),
            unitsOffer: $unitsOffer,
            unitsOther: $unitsOther,
            giftUnits: $giftUnits,
            percentUnits: $percentUnits,
            otherMethod: ['method' => $otherMethod, 'difference' => $this->money($difference)],
            topGifts: $this->topGifts($gifts),
            profit: $this->profit($shop, $offerLines, $unitsOffer),
            capped: $fetched['capped'],
            computedAt: now()->toIso8601String(),
        );
    }

    /**
     * Profit on offer orders: what was paid for each line (after every discount) minus unit cost x units, over the
     * lines whose variant has a cost. null when the app may not read costs or no line has one.
     *
     * @param  list<array>  $lines
     * @return ?array{amount: float, coveredPct: float}
     */
    private function profit(string $shop, array $lines, int $units): ?array
    {
        $variantIds = array_values(array_filter(array_column($lines, 'variant')));
        if ($variantIds === [] || $units === 0) {
            return null;
        }
        $costs = $this->fetcher->unitCosts($shop, $variantIds);
        if ($costs === null) {
            return null;
        }

        $amount = $covered = 0;
        foreach ($lines as $line) {
            $cost = $line['variant'] === null ? null : ($costs[$line['variant']] ?? null);
            if ($cost === null) {
                continue;
            }
            $covered += $line['quantity'];
            $amount += $line['net'] - BogoCalculator::toMinor($cost, $this->decimals) * $line['quantity'];
        }

        return $covered === 0 ? null : ['amount' => $this->money($amount), 'coveredPct' => round($covered * 100 / $units, 1)];
    }

    /**
     * Normalises one order: priced lines, plus the allocations that belong to the offer.
     *
     * @return array{lines: list<array>, ours: list<array>, hasMessages: bool, pct: ?int, gross: int, net: int, units: int}
     */
    private function analyseOrder(array $order): array
    {
        $lines = [];
        $ours = [];
        $hasMessages = false;
        $pct = null;
        $gross = $net = $units = 0;

        foreach ($order['lineItems']['nodes'] ?? [] as $i => $item) {
            $pNow = $this->minor($item['originalUnitPriceSet']['shopMoney']['amount'] ?? 0);
            $compare = $item['variant']['compareAtPrice'] ?? null;
            $quantity = (int) ($item['quantity'] ?? 0);
            $deleted = ($item['product'] ?? null) === null;
            $lineDiscount = 0;
            $lines[$i] = [
                'id' => (string) $i,
                'quantity' => $quantity,
                'pNow' => $pNow,
                'pFull' => max($pNow, $compare === null ? 0 : $this->minor($compare)),
                'product' => $deleted ? 'Deleted product' : (string) ($item['product']['title'] ?? ''),
                'variant' => $item['variant']['id'] ?? null,
                // Same rule as the Function. Refined per order below: tags and collections are read now, not at order time.
                'eligible' => $this->matchesRule($item['product'] ?? null),
                'deleted' => $deleted,
                'net' => 0,
            ];

            foreach ($item['discountAllocations'] ?? [] as $alloc) {
                $amount = $this->minor($alloc['allocatedAmountSet']['shopMoney']['amount'] ?? 0);
                $lineDiscount += $amount;
                // Only automatic discounts can be ours: manual (staff/POS) and code discounts never are.
                if (($alloc['discountApplication']['__typename'] ?? null) !== 'AutomaticDiscountApplication') {
                    continue;
                }
                $title = (string) ($alloc['discountApplication']['title'] ?? '');
                $kind = $this->classify($title);
                if ($kind === null) {
                    continue;
                }
                $hasMessages = $hasMessages || $kind !== 'title';
                if ($kind === 'percent') {
                    $pct ??= $this->percentFrom($title);
                }
                $ours[] = ['kind' => $kind, 'line' => $i, 'amount' => $amount];
            }

            $lines[$i]['net'] = $pNow * $quantity - $lineDiscount;
            $gross += $pNow * $quantity;
            $net += $lines[$i]['net'];
            $units += $quantity;
        }

        // A line that carries one of our allocations took part, whatever its tags say today. A line whose
        // product was deleted has no tags left; in an offer order it is taken to have taken part too.
        $withOurs = array_flip(array_column($ours, 'line'));
        foreach ($lines as $i => &$line) {
            $line['eligible'] = $line['eligible'] || isset($withOurs[$i]) || ($line['deleted'] && $ours !== []);
        }
        unset($line);

        return ['lines' => $lines, 'ours' => $ours, 'hasMessages' => $hasMessages, 'pct' => $pct, 'gross' => $gross, 'net' => $net, 'units' => $units];
    }

    /** Whether the product meets the merchant's rule today: carries the exact (case-sensitive) tag, or is in the collection. */
    private function matchesRule(?array $product): bool
    {
        if ($product === null) {
            return false;
        }

        return $this->appliesTo === 'collection'
            ? ($product['inCollection'] ?? false) === true
            : $this->eligibleTag !== '' && in_array($this->eligibleTag, (array) ($product['tags'] ?? []), true);
    }

    /** @return 'gift'|'percent'|'title'|null null = not ours */
    private function classify(string $title): ?string
    {
        if ($title === '') {
            return null;
        }
        foreach ((array) OfferConstants::get('giftMessages') as $gift) {
            if (strcasecmp($title, (string) $gift) === 0) {
                return 'gift';
            }
        }
        if ($this->percentFrom($title) !== null) {
            return 'percent';
        }
        foreach ([OfferConstants::get('discountTitle'), ...self::LEGACY_DISCOUNT_TITLES] as $known) {
            if (strcasecmp($title, (string) $known) === 0) {
                return 'title';
            }
        }

        return null;
    }

    /** The percent in a percent-message title (any language), if within [minPercent, maxPercent], else null. */
    private function percentFrom(string $title): ?int
    {
        foreach ($this->percentRes ??= self::percentPatterns() as $pattern) {
            if (preg_match($pattern, $title, $m) === 1) {
                $pct = (int) $m[1];
                $inRange = strlen($m[1]) <= 3
                    && $pct >= (int) OfferConstants::get('minPercent')
                    && $pct <= (int) OfferConstants::get('maxPercent');

                return $inRange ? $pct : null;
            }
        }

        return null;
    }

    /**
     * Every percentMessageTemplates entry as an anchored, case-insensitive regex, e.g. "{pct}% off" -> /^(\d+)% off\z/iu.
     *
     * @return list<string>
     */
    private static function percentPatterns(): array
    {
        return array_values(array_map(function (string $template) {
            $parts = explode('{pct}', $template);

            return '/^'.implode('(\d+)', array_map(fn (string $p) => preg_quote($p, '/'), $parts)).'\z/iu';
        }, (array) OfferConstants::get('percentMessageTemplates')));
    }

    /** The percent found in any percent-message title across the range, else the default. */
    private function rangePercent(array $orders): int
    {
        foreach ($orders as $order) {
            if ($order['pct'] !== null) {
                return $order['pct'];
            }
        }

        return (int) OfferConstants::get('defaultPercent');
    }

    /** @return array{giftUnits: int, percentUnits: int, gifts: array<string, array{units: int, value: int}>} */
    private function countsFromMessages(array $order): array
    {
        $giftUnits = $percentUnits = 0;
        $gifts = [];
        foreach ($order['ours'] as $a) {
            if ($a['kind'] === 'percent') {
                $percentUnits++;
            } elseif ($a['kind'] === 'gift') {
                $line = $order['lines'][$a['line']];
                $units = $line['pNow'] > 0 ? (int) round($a['amount'] / $line['pNow']) : 0;
                $giftUnits += $units;
                $gifts[$line['product']]['units'] = ($gifts[$line['product']]['units'] ?? 0) + $units;
                $gifts[$line['product']]['value'] = ($gifts[$line['product']]['value'] ?? 0) + $a['amount'];
            }
        }

        return ['giftUnits' => $giftUnits, 'percentUnits' => $percentUnits, 'gifts' => $gifts];
    }

    /** Fallback when Shopify recorded only the discount title: re-derive the pairing from the cart. */
    private function countsFromRecompute(array $order, int $pct): array
    {
        $giftUnits = $percentUnits = 0;
        $gifts = [];
        $result = BogoCalculator::compute(BogoCalculator::expandUnits($this->eligibleLines($order)), $pct, $this->method);
        foreach ($result->assignments as $a) {
            if ($a['role'] === 'gift' && $a['discount'] > 0) {
                $product = $order['lines'][(int) $a['unit']->lineId]['product'];
                $giftUnits++;
                $gifts[$product]['units'] = ($gifts[$product]['units'] ?? 0) + 1;
                $gifts[$product]['value'] = ($gifts[$product]['value'] ?? 0) + $a['discount'];
            } elseif ($a['role'] === 'single' && $a['discount'] > 0) {
                $percentUnits++;
            }
        }

        return ['giftUnits' => $giftUnits, 'percentUnits' => $percentUnits, 'gifts' => $gifts];
    }

    /**
     * What the method that is not selected would have changed on the same cart, over the eligible lines:
     * selected "customer": what "company" would have charged on top of what was charged (the company's gain);
     * selected "company": what was charged on top of what "customer" would have charged (the customers' saving).
     * Never negative.
     */
    private function otherMethodDifference(array $order, int $pct, string $otherMethod): int
    {
        $lines = $this->eligibleLines($order);
        $gross = 0;
        foreach ($lines as $line) {
            $gross += $line['pNow'] * $line['quantity'];
        }
        // Charged on those same lines (every line with our allocation is eligible, so this is all of ours).
        $ours = array_filter($order['ours'], fn (array $a) => isset($lines[$a['line']]));
        $charged = $gross - array_sum(array_column($ours, 'amount'));
        $alt = BogoCalculator::compute(BogoCalculator::expandUnits($lines), $pct, $otherMethod)->totalPay;

        return max(0, $this->method === 'company' ? $charged - $alt : $alt - $charged);
    }

    /**
     * The order's lines that take part in the offer, keyed as in $order['lines']: matching the rule now, carrying one
     * of our allocations, or (in an offer order) of a deleted product. Other lines contribute nothing to the
     * recompute; the order still counts in ordersTotal.
     */
    private function eligibleLines(array $order): array
    {
        return array_filter($order['lines'], fn (array $line) => $line['eligible']);
    }

    /**
     * @param  array<string, array{units: int, value: int}>  $gifts
     * @return list<array{title: string, units: int, value: float}>
     */
    private function topGifts(array $gifts): array
    {
        $rows = [];
        foreach ($gifts as $title => $g) {
            $rows[] = ['title' => (string) $title, 'units' => $g['units'], 'value' => $g['value']];
        }
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value'] ?: strcmp($a['title'], $b['title']));

        return array_map(
            fn ($r) => ['title' => $r['title'], 'units' => $r['units'], 'value' => $this->money($r['value'])],
            array_slice($rows, 0, 10)
        );
    }

    private function minor(string|float|int $amount): int
    {
        return BogoCalculator::toMinor($amount, $this->decimals);
    }

    /** Minor units -> amount in the shop currency, rounded to its decimals. */
    private function money(int $minor): float
    {
        return round($minor / (10 ** $this->decimals), $this->decimals);
    }
}
