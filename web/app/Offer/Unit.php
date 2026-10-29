<?php

namespace App\Offer;

/** One physical piece in the cart. Prices are integer piastres. */
final class Unit
{
    public function __construct(
        public readonly string $lineId,
        public readonly int $pNow,
        public readonly int $pFull,
    ) {}
}
