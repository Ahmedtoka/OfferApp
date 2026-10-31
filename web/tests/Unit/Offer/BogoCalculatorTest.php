<?php

namespace Tests\Unit\Offer;

use App\Offer\BogoCalculator;
use App\Offer\OfferConstants;
use App\Offer\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BogoCalculatorTest extends TestCase
{
    public static function fixtureCases(): array
    {
        $path = dirname(__DIR__, 4).'/shared/fixtures/bogo-cases.json';
        $cases = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $out = [];
        foreach ($cases as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    private static function pLines(array $lines): array
    {
        return array_map(fn ($l) => [
            'id' => $l['id'],
            'quantity' => $l['quantity'],
            'pNow' => BogoCalculator::toMinor($l['pNow'], 2),
            'pFull' => BogoCalculator::toMinor($l['pFull'], 2),
        ], $lines);
    }

    #[DataProvider('fixtureCases')]
    public function test_matches_shared_fixture(array $case): void
    {
        $lines = self::pLines($case['lines']);
        $units = BogoCalculator::expandUnits($lines);
        $result = BogoCalculator::compute($units, $case['pct'], 'customer');

        $this->assertSame(BogoCalculator::toMinor($case['expectedPay'], 2), $result->totalPay);
        $this->assertSame(BogoCalculator::toMinor($case['expectedSaving'], 2), $result->totalDiscount);

        if (array_key_exists('expectedGifts', $case)) {
            $gifts = [];
            foreach ($result->assignments as $a) {
                if ($a['role'] === 'gift') {
                    $gifts[$a['unit']->lineId] = ($gifts[$a['unit']->lineId] ?? 0) + 1;
                }
            }
            ksort($gifts);
            $expected = $case['expectedGifts'];
            ksort($expected);
            $this->assertSame($expected, $gifts);
        }

        if (array_key_exists('expectedSingle', $case)) {
            $this->assertSame($case['expectedSingle'], $result->singleUnit?->lineId);
        }

        if (isset($case['company'])) {
            $company = $case['company'];
            $result = BogoCalculator::compute($units, $case['pct'], 'company');
            $this->assertSame(BogoCalculator::toMinor($company['expectedPay'], 2), $result->totalPay);
            $this->assertSame(BogoCalculator::toMinor($company['expectedSaving'], 2), $result->totalDiscount);
            if (array_key_exists('expectedSingle', $company)) {
                $this->assertSame($company['expectedSingle'], $result->singleUnit?->lineId);
            }
            $this->assertSame(
                self::expectedPlan($company['perLine']),
                self::byLineId(BogoCalculator::planLineDiscounts($lines, $case['pct'], 'company')),
            );
        }

        if (array_key_exists('perLine', $case)) {
            $expected = [];
            foreach ($case['perLine'] as $id => $p) {
                $expected[] = [
                    'lineId' => (string) $id,
                    'giftQty' => $p['giftQty'],
                    'giftAmount' => BogoCalculator::toMinor($p['giftAmount'], 2),
                    'singleQty' => $p['singleQty'],
                    'singleAmount' => BogoCalculator::toMinor($p['singleAmount'], 2),
                ];
            }
            $actual = BogoCalculator::planLineDiscounts($lines, $case['pct']);
            $key = fn ($r) => $r['lineId'];
            usort($expected, fn ($a, $b) => strcmp($key($a), $key($b)));
            usort($actual, fn ($a, $b) => strcmp($key($a), $key($b)));
            $this->assertSame($expected, $actual);
        }
    }

    #[DataProvider('fixtureCases')]
    public function test_company_is_minimal_against_brute_force(array $case): void
    {
        $units = BogoCalculator::expandUnits(self::pLines($case['lines']));
        $result = BogoCalculator::compute($units, $case['pct'], 'company');

        $this->assertSame(self::bruteForceCompanyDiscount($units, $case['pct']), $result->totalDiscount);
        $this->assertSame(array_sum(array_map(fn (Unit $u) => $u->pNow, $units)) - $result->totalDiscount, $result->totalPay);
    }

    #[DataProvider('fixtureCases')]
    public function test_candidates_mark_exactly_one_chosen_equal_to_compute(array $case): void
    {
        $units = BogoCalculator::expandUnits(self::pLines($case['lines']));
        $sumNow = array_sum(array_map(fn (Unit $u) => $u->pNow, $units));
        foreach (['customer', 'company'] as $method) {
            $result = BogoCalculator::compute($units, $case['pct'], $method);
            $candidates = BogoCalculator::candidates($units, $case['pct'], $method);
            $chosen = array_values(array_filter($candidates, fn ($c) => $c['chosen']));

            $this->assertCount(1, $chosen);
            $this->assertSame($result->totalDiscount, $chosen[0]['totalDiscount']);
            $this->assertSame($result->totalPay, $chosen[0]['totalPay']);
            $this->assertSame($result->singleUnit, $chosen[0]['single']);
            foreach ($candidates as $c) {
                $this->assertSame($sumNow - $c['totalDiscount'], $c['totalPay']);
                $better = $method === 'company'
                    ? $c['totalDiscount'] < $chosen[0]['totalDiscount']
                    : $c['totalDiscount'] > $chosen[0]['totalDiscount'];
                $this->assertFalse($better);
            }
        }
    }

    public function test_candidates_for_even_cart_is_one_candidate_without_single(): void
    {
        $units = [new Unit('A', 100, 100), new Unit('B', 200, 200)];
        $candidates = BogoCalculator::candidates($units, 30, 'customer');

        $this->assertCount(1, $candidates);
        $this->assertNull($candidates[0]['single']);
        $this->assertSame(['A'], array_map(fn (Unit $u) => $u->lineId, $candidates[0]['gifts']));
        $this->assertSame(100, $candidates[0]['totalDiscount']);
        $this->assertSame(200, $candidates[0]['totalPay']);
        $this->assertTrue($candidates[0]['chosen']);
    }

    public function test_odd_cart_has_one_candidate_per_unit(): void
    {
        $units = [new Unit('A', 100, 100), new Unit('B', 200, 200), new Unit('C', 300, 300)];

        $this->assertCount(3, BogoCalculator::candidates($units, 30, 'company'));
    }

    public function test_method_names_and_no_legacy_aliases(): void
    {
        $units = [new Unit('A', 1200, 1200), new Unit('B', 1100, 1100), new Unit('C', 900, 900)];

        $this->assertSame(1830, BogoCalculator::compute($units, 30, 'customer')->totalPay);
        $this->assertSame(BogoCalculator::compute($units, 30)->totalPay, BogoCalculator::compute($units, 30, 'customer')->totalPay);
        $this->assertSame(1970, BogoCalculator::compute($units, 30, 'company')->totalPay);
        // The old analytics-only names are gone: 'min' is just an unknown method now and behaves as the default.
        $this->assertSame(1830, BogoCalculator::compute($units, 30, 'min')->totalPay);
        $this->assertFalse(method_exists(BogoCalculator::class, 'toPiastres'));
    }

    /**
     * Independent of the implementation: for every choice of single, the cheapest valid gift set found
     * by DP over the rest scanned from the dearest down (a gift needs an earlier paid unit to pair with).
     *
     * @param  array<int, Unit>  $units
     */
    private static function bruteForceCompanyDiscount(array $units, int $pct): int
    {
        usort($units, fn (Unit $a, Unit $b) => $b->pNow <=> $a->pNow);
        $n = count($units);
        $best = null;
        foreach ($n % 2 === 1 ? range(0, $n - 1) : [null] as $single) {
            $rest = [];
            $singleDiscount = 0;
            foreach ($units as $k => $u) {
                if ($k === $single) {
                    $singleDiscount = max(0, $u->pNow - intdiv($u->pFull * (100 - $pct) * 2 + 100, 200));
                } else {
                    $rest[] = $u->pNow;
                }
            }
            $gifts = intdiv(count($rest), 2);
            // $state[g] = cheapest gift sum with g gifts so far; each gift needs a distinct earlier paid unit.
            $state = [0 => 0];
            foreach ($rest as $processed => $price) {
                $next = [];
                foreach ($state as $g => $sum) {
                    $next[$g] = min($next[$g] ?? PHP_INT_MAX, $sum);
                    if ($processed - $g >= $g + 1 && $g + 1 <= $gifts) {
                        $next[$g + 1] = min($next[$g + 1] ?? PHP_INT_MAX, $sum + $price);
                    }
                }
                $state = $next;
            }
            $total = $state[$gifts] + $singleDiscount;
            $best = $best === null ? $total : min($best, $total);
        }

        return $best;
    }

    /** @return array<int, array<string, mixed>> */
    private static function expectedPlan(array $perLine): array
    {
        $expected = [];
        foreach ($perLine as $id => $p) {
            $expected[] = [
                'lineId' => (string) $id,
                'giftQty' => $p['giftQty'],
                'giftAmount' => BogoCalculator::toMinor($p['giftAmount'], 2),
                'singleQty' => $p['singleQty'],
                'singleAmount' => BogoCalculator::toMinor($p['singleAmount'], 2),
            ];
        }

        return self::byLineId($expected);
    }

    /** @param  array<int, array<string, mixed>>  $plan */
    private static function byLineId(array $plan): array
    {
        usort($plan, fn ($a, $b) => strcmp($a['lineId'], $b['lineId']));

        return $plan;
    }

    public function test_single_discount_rounds_half_up_at_boundary(): void
    {
        // 100001 * 70 / 100 = 70000.7 -> 70001; discount = 100001 - 70001 = 30000
        $r = BogoCalculator::compute([new Unit('A', 100001, 100001)], 30);
        $this->assertSame(30000, $r->totalDiscount);
        $this->assertSame(70001, $r->totalPay);

        // 5 * 50 / 100 = 2.5 -> 3; discount = 2
        $r = BogoCalculator::compute([new Unit('A', 5, 5)], 50);
        $this->assertSame(2, $r->totalDiscount);
        $this->assertSame(3, $r->totalPay);
    }

    public function test_single_unit_set_for_odd_cart_even_with_zero_discount(): void
    {
        $r = BogoCalculator::compute([new Unit('A', 100, 1000)], 30);
        $this->assertSame(0, $r->totalDiscount);
        $this->assertSame('A', $r->singleUnit?->lineId);
    }

    public function test_even_cart_has_no_single(): void
    {
        $r = BogoCalculator::compute([new Unit('A', 100, 100), new Unit('B', 200, 200)], 30);
        $this->assertNull($r->singleUnit);
        $this->assertSame(100, $r->totalDiscount);
    }

    public function test_to_piastres(): void
    {
        $this->assertSame(120000, BogoCalculator::toMinor('1200.0', 2));
        $this->assertSame(120000, BogoCalculator::toMinor(1200, 2));
        $this->assertSame(120000, BogoCalculator::toMinor(1200.0, 2));
        $this->assertSame(1, BogoCalculator::toMinor('0.005', 2));
        $this->assertSame(0, BogoCalculator::toMinor('0.004', 2));
        $this->assertSame(150, BogoCalculator::toMinor('1.5', 2));
        $this->assertSame(1999, BogoCalculator::toMinor(19.99, 2));
        $this->assertSame(-150, BogoCalculator::toMinor('-1.50', 2));
        $this->assertSame(0, BogoCalculator::toMinor('', 2));
    }

    public function test_to_minor_for_any_decimals(): void
    {
        $this->assertSame(12345, BogoCalculator::toMinor('12.345', 3));
        $this->assertSame(1500, BogoCalculator::toMinor('1500', 0));
        $this->assertSame(1501, BogoCalculator::toMinor('1500.5', 0));
        $this->assertSame(1, BogoCalculator::toMinor('0.0005', 3));
        $this->assertSame(1235, BogoCalculator::toMinor(1.235, 3));
        $this->assertSame(-150, BogoCalculator::toMinor('-1.50', 2));
        $this->assertSame(0, BogoCalculator::toMinor('', 3));
    }

    public function test_decimals_for_currency(): void
    {
        $this->assertSame(3, BogoCalculator::decimalsFor('KWD'));
        $this->assertSame(0, BogoCalculator::decimalsFor('JPY'));
        $this->assertSame(2, BogoCalculator::decimalsFor('EGP'));
        $this->assertSame(2, BogoCalculator::decimalsFor('XXX'));
        $this->assertSame(3, BogoCalculator::decimalsFor('kwd'));
    }

    public function test_offer_constants(): void
    {
        config(['shopify.shared_constants_path' => dirname(__DIR__, 4).'/shared/offer-constants.json']);
        $this->assertSame(30, OfferConstants::get('defaultPercent'));
        $this->assertSame('BOGO 30% off', OfferConstants::percentMessage(30));
    }

    public function test_decimals_for_resolves_a_relative_shared_path_against_the_app_root(): void
    {
        config(['shopify.shared_constants_path' => '../shared/offer-constants.json']);
        $original = getcwd();
        $tmp = sys_get_temp_dir();
        chdir($tmp);
        try {
            $this->assertSame(3, BogoCalculator::decimalsFor('KWD'));
        } finally {
            chdir($original);
        }
    }
}
