<?php

namespace App\Offer;

/** Buy one get one free, or a lone piece at pct% off. Port of extensions/bogo-discount/src/bogo.js. */
final class BogoCalculator
{
    /** @var array<string, array<string, mixed>> */
    private static array $decimals = [];

    /**
     * @param  array<int, array{id: string|int, quantity: int, pNow: int, pFull: int}>  $lines
     * @return array<int, Unit>
     */
    public static function expandUnits(array $lines): array
    {
        $units = [];
        foreach ($lines as $line) {
            for ($i = 0; $i < $line['quantity']; $i++) {
                $units[] = new Unit((string) $line['id'], $line['pNow'], $line['pFull']);
            }
        }

        return $units;
    }

    /**
     * @param  array<int, Unit>  $units
     * @return array<int, Unit>
     */
    private static function sortUnits(array $units): array
    {
        $sorted = array_values($units);
        usort($sorted, fn (Unit $a, Unit $b) => $a->pNow <=> $b->pNow
            ?: $a->pFull <=> $b->pFull
            ?: strcmp($a->lineId, $b->lineId));

        return $sorted;
    }

    /** Discount on the lone piece: pNow minus pFull*(100-pct)/100 rounded half-up, floored at 0. */
    private static function singleDiscount(Unit $unit, int $pct): int
    {
        // Exact integer half-up of pFull*(100-pct)/100 (no float drift).
        $target = intdiv($unit->pFull * (100 - $pct) * 2 + 100, 200);

        return max(0, $unit->pNow - $target);
    }

    /**
     * Pairs sorted units adjacently (cheaper pNow of each pair is the gift).
     *
     * @param  array<int, Unit>  $sorted
     * @param  array<int, array{unit: Unit, role: string, discount: int}>  $assignments
     */
    private static function pairUp(array $sorted, array &$assignments): int
    {
        $discount = 0;
        for ($i = 0; $i + 1 < count($sorted); $i += 2) {
            $assignments[] = ['unit' => $sorted[$i], 'role' => 'gift', 'discount' => $sorted[$i]->pNow];
            $assignments[] = ['unit' => $sorted[$i + 1], 'role' => 'paid', 'discount' => 0];
            $discount += $sorted[$i]->pNow;
        }

        return $discount;
    }

    /**
     * Company: the cheapest floor(n/2) units are the gifts, the dearer half pay.
     *
     * @param  array<int, Unit>  $sorted
     * @param  array<int, array{unit: Unit, role: string, discount: int}>  $assignments
     */
    private static function giftCheapest(array $sorted, array &$assignments): int
    {
        $giftCount = intdiv(count($sorted), 2);
        $discount = 0;
        foreach ($sorted as $k => $unit) {
            if ($k < $giftCount) {
                $assignments[] = ['unit' => $unit, 'role' => 'gift', 'discount' => $unit->pNow];
                $discount += $unit->pNow;
            } else {
                $assignments[] = ['unit' => $unit, 'role' => 'paid', 'discount' => 0];
            }
        }

        return $discount;
    }

    /** @param  array<int, Unit>  $sorted */
    private static function build(array $sorted, ?int $singleIndex, int $pct, string $method): OfferResult
    {
        $group = $method === 'company' ? self::giftCheapest(...) : self::pairUp(...);
        $assignments = [];
        $singleUnit = null;
        if ($singleIndex === null) {
            $totalDiscount = $group($sorted, $assignments);
        } else {
            $singleUnit = $sorted[$singleIndex];
            $rest = [];
            foreach ($sorted as $k => $u) {
                if ($k !== $singleIndex) {
                    $rest[] = $u;
                }
            }
            $pairs = [];
            $pairDiscount = $group($rest, $pairs);
            $sd = self::singleDiscount($singleUnit, $pct);
            $assignments = [...$pairs, ['unit' => $singleUnit, 'role' => 'single', 'discount' => $sd]];
            $totalDiscount = $pairDiscount + $sd;
        }
        $sumNow = array_sum(array_map(fn (Unit $u) => $u->pNow, $sorted));

        return new OfferResult($assignments, $totalDiscount, $sumNow - $totalDiscount, $singleUnit);
    }

    /**
     * Index of the lone piece in the sorted units (null for an even cart).
     *
     * @param  array<int, Unit>  $sorted
     */
    private static function chooseSingleIndex(array $sorted, int $pct, string $method): ?int
    {
        if (count($sorted) % 2 === 0) {
            return null;
        }

        $smallest = $method === 'company';
        $best = null;
        $bestIndex = -1;
        foreach (array_keys($sorted) as $i) {
            $candidate = self::build($sorted, $i, $pct, $method);
            if ($best === null) {
                $best = $candidate;
                $bestIndex = $i;

                continue;
            }
            $d = $candidate->totalDiscount - $best->totalDiscount;
            $better = $smallest ? $d < 0 : $d > 0;
            // Ties: smaller pFull wins, then smaller sorted index (the earlier candidate).
            $tieWin = $d === 0 && $sorted[$i]->pFull < $sorted[$bestIndex]->pFull;
            if ($better || $tieWin) {
                $best = $candidate;
                $bestIndex = $i;
            }
        }

        return $bestIndex;
    }

    /**
     * @param  array<int, Unit>  $units
     * @param  string  $method  'customer' (largest saving) or 'company' (smallest valid saving).
     */
    public static function compute(array $units, int $pct, string $method = 'customer'): OfferResult
    {
        $sorted = self::sortUnits($units);

        return self::build($sorted, self::chooseSingleIndex($sorted, $pct, $method), $pct, $method);
    }

    /**
     * Every choice of lone piece (a single candidate for an even cart), for the Test page.
     * Exactly one is chosen: the one compute() returns.
     *
     * @param  array<int, Unit>  $units
     * @return list<array{single: ?Unit, gifts: list<Unit>, totalDiscount: int, totalPay: int, chosen: bool}>
     */
    public static function candidates(array $units, int $pct, string $method): array
    {
        $sorted = self::sortUnits($units);
        $chosenIndex = self::chooseSingleIndex($sorted, $pct, $method);

        $out = [];
        foreach ($chosenIndex === null ? [null] : array_keys($sorted) as $i) {
            $result = self::build($sorted, $i, $pct, $method);
            $gifts = [];
            foreach ($result->assignments as $a) {
                if ($a['role'] === 'gift') {
                    $gifts[] = $a['unit'];
                }
            }
            $out[] = [
                'single' => $result->singleUnit,
                'gifts' => $gifts,
                'totalDiscount' => $result->totalDiscount,
                'totalPay' => $result->totalPay,
                'chosen' => $i === $chosenIndex,
            ];
        }

        return $out;
    }

    /**
     * Per-line discount plan for the chosen result; lines with nothing to discount are omitted.
     *
     * @param  array<int, array{id: string|int, quantity: int, pNow: int, pFull: int}>  $lines
     * @return array<int, array{lineId: string, giftQty: int, giftAmount: int, singleQty: int, singleAmount: int}>
     */
    public static function planLineDiscounts(array $lines, int $pct, string $method = 'customer'): array
    {
        $result = self::compute(self::expandUnits($lines), $pct, $method);
        $byLine = [];
        foreach ($result->assignments as $a) {
            $unit = $a['unit'];
            if ($a['role'] === 'paid' || $a['discount'] <= 0) {
                continue;
            }
            $entry = $byLine[$unit->lineId] ?? ['lineId' => $unit->lineId, 'giftQty' => 0, 'giftAmount' => 0, 'singleQty' => 0, 'singleAmount' => 0];
            if ($a['role'] === 'gift') {
                $entry['giftQty'] += 1;
                $entry['giftAmount'] += $a['discount'];
            } else {
                $entry['singleQty'] = 1;
                $entry['singleAmount'] += $a['discount'];
            }
            $byLine[$unit->lineId] = $entry;
        }

        $plan = [];
        foreach ($lines as $line) {
            $id = (string) $line['id'];
            if (isset($byLine[$id])) {
                $plan[] = $byLine[$id];
            }
        }

        return $plan;
    }

    /** Decimal string/number -> integer minor units for $decimals, rounded half-up, via integer math on the digits. */
    public static function toMinor(string|float|int $amount, int $decimals): int
    {
        [$intPart, $fracPart] = array_pad(explode('.', trim((string) $amount), 2), 2, '');
        $negative = str_starts_with($intPart, '-');
        $whole = abs((int) $intPart) * (10 ** $decimals);
        $digits = $decimals === 0 ? 0 : (int) substr(str_pad($fracPart, $decimals, '0'), 0, $decimals);
        $roundUp = strlen($fracPart) > $decimals && $fracPart[$decimals] >= '5' ? 1 : 0;
        $result = $whole + $digits + $roundUp;

        return $negative ? -$result : $result;
    }

    /** ISO 4217 currency code -> number of decimals (unknown codes use the default of 2). */
    public static function decimalsFor(string $currency): int
    {
        $path = dirname((string) config('shopify.shared_constants_path')).'/currency-decimals.json';
        if (! isset(self::$decimals[$path])) {
            self::$decimals[$path] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }
        $table = self::$decimals[$path];
        $code = strtoupper(trim($currency));

        return match (true) {
            in_array($code, $table['zero'], true) => 0,
            in_array($code, $table['three'], true) => 3,
            default => (int) $table['default'],
        };
    }
}
