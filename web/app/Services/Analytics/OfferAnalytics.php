<?php

namespace App\Services\Analytics;

use App\Offer\BogoCalculator;
use App\Offer\OfferConstants;
use App\Services\OfferSettings;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Support\Facades\Cache;

/** Live offer numbers from Admin GraphQL orders. Internally everything is integer piastres. */
class OfferAnalytics
{
    /**
     * Arabic allocation titles the offer used before the English switch (Oct 2026). Orders placed
     * before then still carry them, so analytics must keep recognising them. Never emitted again.
     */
    private const LEGACY_TITLES = [
        'gift' => 'هدية',
        'percent' => '/^خصم (\d+)%\z/u',
        'title' => 'اشتري واحدة وخد واحدة',
    ];

    private bool $fromMessages = true;

    /** Current-template percent regex, built once per compute. */
    private ?string $percentRe = null;

    /** The merchant's eligible tag (shop config), set per compute. */
    private string $eligibleTag = '';

    public function __construct(private OrdersFetcher $fetcher, private OfferSettings $settings) {}

    /** false = ignore gift/percent titles and always derive unit counts by recomputing the cart. */
    public function classifyFromMessages(bool $on): static
    {
        $this->fromMessages = $on;

        return $this;
    }

    /** @throws ShopifyGraphqlException */
    public function compute(string $shop, int $rangeDays): AnalyticsResult
    {
        // The tag is part of the cache key: changing it on the settings page recomputes at once.
        $this->eligibleTag = (string) $this->settings->read($shop)['eligibleTag'];
        $cached = Cache::remember(
            "analytics:{$shop}:{$rangeDays}:".sha1($this->eligibleTag),
            (int) OfferConstants::get('analyticsCacheSeconds'),
            fn () => $this->build($shop, $rangeDays)->toArray()
        );

        return AnalyticsResult::fromArray($cached);
    }

    private function build(string $shop, int $rangeDays): AnalyticsResult
    {
        $this->percentRe = self::percentPattern();
        $fetched = $this->fetcher->fetch($shop, $rangeDays, (int) OfferConstants::get('analyticsOrderCap'));
        $orders = array_map(fn (array $o) => $this->analyseOrder($o), $fetched['orders']);
        $rangePct = $this->rangePercent($orders);

        $offerOrders = $giftUnits = $percentUnits = $totalDiscount = $scenario2 = 0;
        $gifts = [];
        foreach ($orders as $order) {
            if ($order['ours'] === []) {
                continue;
            }
            $offerOrders++;
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

            $scenario2 += $this->scenario2Extra($order, $pct);
        }

        $ordersTotal = count($orders);

        return new AnalyticsResult(
            rangeDays: $rangeDays,
            ordersTotal: $ordersTotal,
            offerOrders: $offerOrders,
            offerOrdersPct: $ordersTotal === 0 ? 0.0 : round($offerOrders * 100 / $ordersTotal, 1),
            giftUnits: $giftUnits,
            percentUnits: $percentUnits,
            totalDiscount: $this->egp($totalDiscount),
            scenario2Extra: $this->egp($scenario2),
            topGifts: $this->topGifts($gifts),
            capped: $fetched['capped'],
            computedAt: now()->toIso8601String(),
        );
    }

    /**
     * Normalises one order: priced lines, plus the allocations that belong to the offer.
     *
     * @return array{lines: list<array>, ours: list<array>, hasMessages: bool, pct: ?int}
     */
    private function analyseOrder(array $order): array
    {
        $lines = [];
        $ours = [];
        $hasMessages = false;
        $pct = null;
        $eligibleTag = $this->eligibleTag;

        foreach ($order['lineItems']['nodes'] ?? [] as $i => $item) {
            $pNow = BogoCalculator::toPiastres($item['originalUnitPriceSet']['shopMoney']['amount'] ?? 0);
            $compare = $item['variant']['compareAtPrice'] ?? null;
            $lines[$i] = [
                'id' => (string) $i,
                'quantity' => (int) ($item['quantity'] ?? 0),
                'pNow' => $pNow,
                'pFull' => max($pNow, $compare === null ? 0 : BogoCalculator::toPiastres($compare)),
                'product' => ($item['product'] ?? null) === null
                    ? 'Deleted product'
                    : (string) ($item['product']['title'] ?? ''),
                // Same rule as the Function: only products carrying the exact (case-sensitive) tag take part.
                // Refined per order below: tags are read now, not at order time.
                'eligible' => in_array($eligibleTag, (array) ($item['product']['tags'] ?? []), true),
                'deleted' => ($item['product'] ?? null) === null,
            ];

            foreach ($item['discountAllocations'] ?? [] as $alloc) {
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
                $ours[] = [
                    'kind' => $kind,
                    'line' => $i,
                    'amount' => BogoCalculator::toPiastres($alloc['allocatedAmountSet']['shopMoney']['amount'] ?? 0),
                ];
            }
        }

        // A line that carries one of our allocations took part, whatever its tags say today. A line whose
        // product was deleted has no tags left; in an offer order it is taken to have taken part too.
        $withOurs = array_flip(array_column($ours, 'line'));
        foreach ($lines as $i => &$line) {
            $line['eligible'] = $line['eligible'] || isset($withOurs[$i]) || ($line['deleted'] && $ours !== []);
        }
        unset($line);

        return ['lines' => $lines, 'ours' => $ours, 'hasMessages' => $hasMessages, 'pct' => $pct];
    }

    /** @return 'gift'|'percent'|'title'|null null = not ours */
    private function classify(string $title): ?string
    {
        return match (true) {
            $title === '' => null,
            strcasecmp($title, (string) OfferConstants::get('giftMessage')) === 0, $title === self::LEGACY_TITLES['gift'] => 'gift',
            $this->percentFrom($title) !== null => 'percent',
            strcasecmp($title, (string) OfferConstants::get('discountTitle')) === 0, $title === self::LEGACY_TITLES['title'] => 'title',
            default => null,
        };
    }

    /** The percent in a current or legacy percent-message title, if within [minPercent, maxPercent], else null. */
    private function percentFrom(string $title): ?int
    {
        foreach ([$this->percentRe ??= self::percentPattern(), self::LEGACY_TITLES['percent']] as $pattern) {
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

    /** percentMessageTemplate as an anchored, case-insensitive regex, e.g. "{pct}% off" -> /^(\d+)% off\z/iu. */
    private static function percentPattern(): string
    {
        $parts = explode('{pct}', (string) OfferConstants::get('percentMessageTemplate'));

        return '/^'.implode('(\d+)', array_map(fn (string $p) => preg_quote($p, '/'), $parts)).'\z/iu';
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
        $result = BogoCalculator::compute(BogoCalculator::expandUnits($this->eligibleLines($order)), $pct, 'max');
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
     * What the margin-protecting variant (smallest saving) would have charged above what was charged.
     * Never negative; an even unit count is identical to scenario 1, so contributes 0.
     */
    private function scenario2Extra(array $order, int $pct): int
    {
        $lines = $this->eligibleLines($order);
        $gross = 0;
        foreach ($lines as $line) {
            $gross += $line['pNow'] * $line['quantity'];
        }
        // Charged on those same lines (every line with our allocation is eligible, so this is all of ours).
        $ours = array_filter($order['ours'], fn (array $a) => isset($lines[$a['line']]));
        $charged = $gross - array_sum(array_column($ours, 'amount'));
        $alt = BogoCalculator::compute(BogoCalculator::expandUnits($lines), $pct, 'min')->totalPay;

        return max(0, $alt - $charged);
    }

    /**
     * The order's lines that take part in the offer, keyed as in $order['lines']: tagged now, carrying one of
     * our allocations, or (in an offer order) of a deleted product. Other lines contribute nothing to the scenario recompute; the order still counts in ordersTotal.
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
            fn ($r) => ['title' => $r['title'], 'units' => $r['units'], 'value' => $this->egp($r['value'])],
            array_slice($rows, 0, 10)
        );
    }

    private function egp(int $piastres): float
    {
        return round($piastres / 100, 2);
    }
}
