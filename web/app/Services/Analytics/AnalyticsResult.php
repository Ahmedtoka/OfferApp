<?php

namespace App\Services\Analytics;

/** Analytics tiles + top-gifts table. Money is EGP rounded to 2 dp; offerOrdersPct to 1 dp. */
final class AnalyticsResult
{
    /** @param  list<array{title: string, units: int, value: float}>  $topGifts */
    public function __construct(
        public readonly int $rangeDays,
        public readonly int $ordersTotal,
        public readonly int $offerOrders,
        public readonly float $offerOrdersPct,
        public readonly int $giftUnits,
        public readonly int $percentUnits,
        public readonly float $totalDiscount,
        public readonly float $scenario2Extra,
        public readonly array $topGifts,
        public readonly bool $capped,
        public readonly string $computedAt,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self(
            $a['rangeDays'], $a['ordersTotal'], $a['offerOrders'], (float) $a['offerOrdersPct'], $a['giftUnits'],
            $a['percentUnits'], (float) $a['totalDiscount'], (float) $a['scenario2Extra'], $a['topGifts'], $a['capped'], $a['computedAt'],
        );
    }
}
