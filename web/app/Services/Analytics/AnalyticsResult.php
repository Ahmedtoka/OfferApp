<?php

namespace App\Services\Analytics;

/**
 * Analytics tiles + top-gifts table. Money is in the shop's currency, rounded to its decimals;
 * percentages to 1 dp. aov* are null with no orders of that kind; profit is null without cost data.
 */
final class AnalyticsResult
{
    /**
     * @param  array{method: string, difference: float}  $otherMethod
     * @param  list<array{title: string, units: int, value: float}>  $topGifts
     * @param  ?array{amount: float, coveredPct: float}  $profit
     */
    public function __construct(
        public readonly string $currency,
        public readonly int $rangeDays,
        public readonly int $ordersTotal,
        public readonly int $offerOrders,
        public readonly float $offerOrdersPct,
        public readonly float $offerSales,
        public readonly float $totalDiscount,
        public readonly float $discountPctOfSales,
        public readonly ?float $aovOffer,
        public readonly ?float $aovOther,
        public readonly int $unitsOffer,
        public readonly int $unitsOther,
        public readonly int $giftUnits,
        public readonly int $percentUnits,
        public readonly array $otherMethod,
        public readonly array $topGifts,
        public readonly ?array $profit,
        public readonly bool $capped,
        public readonly string $computedAt,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        $float = fn (mixed $v): ?float => $v === null ? null : (float) $v;

        return new self(
            $a['currency'], $a['rangeDays'], $a['ordersTotal'], $a['offerOrders'], (float) $a['offerOrdersPct'],
            (float) $a['offerSales'], (float) $a['totalDiscount'], (float) $a['discountPctOfSales'],
            $float($a['aovOffer']), $float($a['aovOther']), $a['unitsOffer'], $a['unitsOther'], $a['giftUnits'], $a['percentUnits'],
            $a['otherMethod'], $a['topGifts'], $a['profit'], $a['capped'], $a['computedAt'],
        );
    }
}
