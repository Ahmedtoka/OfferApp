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
            'pNow' => BogoCalculator::toPiastres($l['pNow']),
            'pFull' => BogoCalculator::toPiastres($l['pFull']),
        ], $lines);
    }

    #[DataProvider('fixtureCases')]
    public function test_matches_shared_fixture(array $case): void
    {
        $lines = self::pLines($case['lines']);
        $units = BogoCalculator::expandUnits($lines);
        $result = BogoCalculator::compute($units, $case['pct']);

        $this->assertSame(BogoCalculator::toPiastres($case['expectedPay']), $result->totalPay);
        $this->assertSame(BogoCalculator::toPiastres($case['expectedSaving']), $result->totalDiscount);

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

        if (array_key_exists('scenario2Pay', $case)) {
            $min = BogoCalculator::compute($units, $case['pct'], 'min');
            $this->assertSame(BogoCalculator::toPiastres($case['scenario2Pay']), $min->totalPay);
        }

        if (array_key_exists('perLine', $case)) {
            $expected = [];
            foreach ($case['perLine'] as $id => $p) {
                $expected[] = [
                    'lineId' => (string) $id,
                    'giftQty' => $p['giftQty'],
                    'giftAmount' => BogoCalculator::toPiastres($p['giftAmount']),
                    'singleQty' => $p['singleQty'],
                    'singleAmount' => BogoCalculator::toPiastres($p['singleAmount']),
                ];
            }
            $actual = BogoCalculator::planLineDiscounts($lines, $case['pct']);
            $key = fn ($r) => $r['lineId'];
            usort($expected, fn ($a, $b) => strcmp($key($a), $key($b)));
            usort($actual, fn ($a, $b) => strcmp($key($a), $key($b)));
            $this->assertSame($expected, $actual);
        }
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
        $this->assertSame(120000, BogoCalculator::toPiastres('1200.0'));
        $this->assertSame(120000, BogoCalculator::toPiastres(1200));
        $this->assertSame(120000, BogoCalculator::toPiastres(1200.0));
        $this->assertSame(1, BogoCalculator::toPiastres('0.005'));
        $this->assertSame(0, BogoCalculator::toPiastres('0.004'));
        $this->assertSame(150, BogoCalculator::toPiastres('1.5'));
        $this->assertSame(1999, BogoCalculator::toPiastres(19.99));
        $this->assertSame(-150, BogoCalculator::toPiastres('-1.50'));
        $this->assertSame(0, BogoCalculator::toPiastres(''));
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
        $this->assertSame('30% off', OfferConstants::percentMessage(30));
    }
}
