<?php

namespace App\Offer;

final class OfferResult
{
    /**
     * @param  array<int, array{unit: Unit, role: 'gift'|'single'|'paid', discount: int}>  $assignments
     */
    public function __construct(
        public readonly array $assignments,
        public readonly int $totalDiscount,
        public readonly int $totalPay,
        public readonly ?Unit $singleUnit,
    ) {}
}
