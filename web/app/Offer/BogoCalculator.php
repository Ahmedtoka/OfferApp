<?php

namespace App\Offer;

/** Buy one get one free, or a lone piece at pct% off. Port of extensions/bogo-discount/src/bogo.js. */
final class BogoCalculator
{
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

    /** @param  array<int, Unit>  $sorted */
    private static function build(array $sorted, ?int $singleIndex, int $pct): OfferResult
    {
        $assignments = [];
        $singleUnit = null;
        if ($singleIndex === null) {
            $totalDiscount = self::pairUp($sorted, $assignments);
        } else {
            $singleUnit = $sorted[$singleIndex];
            $rest = [];
            foreach ($sorted as $k => $u) {
                if ($k !== $singleIndex) {
                    $rest[] = $u;
                }
            }
            $pairs = [];
            $pairDiscount = self::pairUp($rest, $pairs);
            $sd = self::singleDiscount($singleUnit, $pct);
            $assignments = [...$pairs, ['unit' => $singleUnit, 'role' => 'single', 'discount' => $sd]];
            $totalDiscount = $pairDiscount + $sd;
        }
        $sumNow = array_sum(array_map(fn (Unit $u) => $u->pNow, $sorted));

        return new OfferResult($assignments, $totalDiscount, $sumNow - $totalDiscount, $singleUnit);
    }

    /**
     * @param  array<int, Unit>  $units
     * @param  string  $mode  'max' (best saving) or 'min' (smallest saving)
     */
    public static function compute(array $units, int $pct, string $mode = 'max'): OfferResult
    {
        $sorted = self::sortUnits($units);
        if (count($sorted) % 2 === 0) {
            return self::build($sorted, null, $pct);
        }

        $best = null;
        $bestIndex = -1;
        foreach (array_keys($sorted) as $i) {
            $candidate = self::build($sorted, $i, $pct);
            if ($best === null) {
                $best = $candidate;
                $bestIndex = $i;

                continue;
            }
            $d = $candidate->totalDiscount - $best->totalDiscount;
            $better = $mode === 'min' ? $d < 0 : $d > 0;
            // Ties: smaller pFull wins, then smaller sorted index (the earlier candidate).
            $tieWin = $d === 0 && $sorted[$i]->pFull < $sorted[$bestIndex]->pFull;
            if ($better || $tieWin) {
                $best = $candidate;
                $bestIndex = $i;
            }
        }

        return $best;
    }

    /**
     * Per-line discount plan for the 'max' result; lines with nothing to discount are omitted.
     *
     * @param  array<int, array{id: string|int, quantity: int, pNow: int, pFull: int}>  $lines
     * @return array<int, array{lineId: string, giftQty: int, giftAmount: int, singleQty: int, singleAmount: int}>
     */
    public static function planLineDiscounts(array $lines, int $pct): array
    {
        $result = self::compute(self::expandUnits($lines), $pct, 'max');
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

    /** Decimal string/number of EGP -> integer piastres, rounded half-up, via integer math on the digits. */
    public static function toPiastres(string|float|int $amount): int
    {
        [$intPart, $fracPart] = array_pad(explode('.', trim((string) $amount), 2), 2, '');
        $negative = str_starts_with($intPart, '-');
        $whole = abs((int) $intPart) * 100;
        $digits = (int) substr($fracPart.'00', 0, 2);
        $roundUp = strlen($fracPart) > 2 && $fracPart[2] >= '5' ? 1 : 0;
        $result = $whole + $digits + $roundUp;

        return $negative ? -$result : $result;
    }
}
