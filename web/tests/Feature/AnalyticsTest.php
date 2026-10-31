<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Analytics\OfferAnalytics;
use App\Services\Analytics\OrdersFetcher;
use App\Shopify\GraphqlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeOrdersClient;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        Cache::flush();
        Shop::create([
            'shop_domain' => SessionTokens::SHOP,
            'access_token' => 'shpat_existing',
            'scopes' => 'read_orders',
            'installed_at' => now(),
        ]);
    }

    private function fake(array $pages): FakeOrdersClient
    {
        $fake = new FakeOrdersClient($pages);
        $fake->settingsValue = self::settings();
        $this->app->instance(GraphqlClient::class, $fake);

        return $fake;
    }

    private static function settings(array $over = []): string
    {
        return json_encode($over + ['enabled' => true, 'percent' => 30, 'method' => 'customer', 'appliesTo' => 'tag', 'eligibleTag' => 'FallOffer', 'collectionId' => null]);
    }

    /** One order-line node for an inline page. $o: qty, price, title, tags, variant, inCollection, allocs [[amount, title, type?]], compareAt. */
    private static function line(array $o): array
    {
        $o += ['qty' => 1, 'title' => 'Product A', 'tags' => ['FallOffer'], 'variant' => null, 'inCollection' => false, 'allocs' => [], 'compareAt' => null];

        return [
            'quantity' => $o['qty'],
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => (string) $o['price']]],
            'variant' => $o['variant'] === null ? null : ['id' => $o['variant'], 'compareAtPrice' => $o['compareAt']],
            'product' => ['id' => 'gid://shopify/Product/1', 'title' => $o['title'], 'tags' => $o['tags'], 'inCollection' => $o['inCollection']],
            'discountAllocations' => array_map(fn (array $a) => [
                'allocatedAmountSet' => ['shopMoney' => ['amount' => (string) $a[0]]],
                'discountApplication' => ['__typename' => $a[2] ?? 'AutomaticDiscountApplication', 'title' => $a[1]],
            ], $o['allocs']),
        ];
    }

    /** An inline orders page from lists of line nodes, one list per order. */
    private static function page(array ...$orders): array
    {
        return self::pageAt('2026-10-28T10:00:00+00:00', ...$orders);
    }

    /** Like page(), with every order created at $createdAt. */
    private static function pageAt(string $createdAt, array ...$orders): array
    {
        return ['orders' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => array_map(fn (array $lines, int $i) => [
                'id' => 'gid://shopify/Order/'.(9000 + $i), 'name' => '#'.(9000 + $i), 'createdAt' => $createdAt,
                'cancelledAt' => null, 'lineItems' => ['nodes' => $lines],
            ], $orders, array_keys($orders)),
        ]];
    }

    private function api(string $url = '/api/analytics')
    {
        return $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint())->getJson($url);
    }

    public function test_tiles_for_the_worked_examples_fixture_set(): void
    {
        $this->fake(['orders_all_rows']);

        $res = $this->api('/api/analytics?range=30')->assertOk();

        $res->assertJsonPath('rangeDays', 30)
            ->assertJsonPath('ordersTotal', 9)
            ->assertJsonPath('offerOrders', 7)
            ->assertJsonPath('giftUnits', 8)
            ->assertJsonPath('percentUnits', 4)
            ->assertJsonPath('capped', false);
        $this->assertSame(77.8, $res->json('offerOrdersPct'));
        $this->assertEquals(9370.0, $res->json('totalDiscount'));
        $this->assertSame('EGP', $res->json('currency'));
        $this->assertSame('company', $res->json('otherMethod.method'));
        $this->assertEquals(750.0, $res->json('otherMethod.difference'));
        $this->assertEquals([
            ['title' => 'Product B', 'units' => 3, 'value' => 3300.0],
            ['title' => 'Product A', 'units' => 2, 'value' => 2400.0],
            ['title' => 'Product D', 'units' => 2, 'value' => 1600.0],
            ['title' => 'Product C', 'units' => 1, 'value' => 900.0],
        ], $res->json('topGifts'));
        $this->assertNotNull($res->json('computedAt'));
    }

    public function test_cancelled_offer_order_counts_nowhere(): void
    {
        // orders_all_rows plus one cancelled offer order (#1999: a 5000 gift and a 300 single).
        $this->fake(['orders_with_cancelled']);

        $res = $this->api('/api/analytics?range=30')->assertOk();

        $res->assertJsonPath('ordersTotal', 9)
            ->assertJsonPath('offerOrders', 7)
            ->assertJsonPath('giftUnits', 8)
            ->assertJsonPath('percentUnits', 4);
        $this->assertSame(77.8, $res->json('offerOrdersPct'));
        $this->assertEquals(9370.0, $res->json('totalDiscount'));
        $this->assertEquals(750.0, $res->json('otherMethod.difference'));
        $this->assertNotContains('Product Cancelled', array_column($res->json('topGifts'), 'title'));
        $this->assertSame('Product B', $res->json('topGifts.0.title'));
    }

    public function test_orders_query_excludes_cancelled_orders(): void
    {
        $fake = $this->fake(['orders_all_rows']);

        $this->api('/api/analytics?range=30')->assertOk();

        $this->assertStringContainsString('-status:cancelled', $fake->calls[0]['variables']['q']);
    }

    public function test_orders_query_asks_for_product_tags(): void
    {
        $fake = $this->fake(['orders_all_rows']);

        $this->api()->assertOk();

        $this->assertStringContainsString('product { id title tags }', $fake->calls[0]['query']);
        $this->assertStringContainsString('shop { currencyCode }', $fake->calls[0]['query']);
        $this->assertStringNotContainsString('inCollection', $fake->calls[0]['query']);
    }

    public function test_untagged_lines_are_left_out_of_the_scenario_recompute(): void
    {
        // A 1200 + B 1100 (gift) + C 900 (30% off), all tagged FallOffer, plus an untagged 500 line
        // whose tags are ["Summer", "falloffer"] (wrong case does not count) and no allocation.
        foreach ([true, false] as $fromMessages) {
            Cache::flush();
            $this->fake(['orders_mixed_untagged']);

            $result = $this->app->make(OfferAnalytics::class)->classifyFromMessages($fromMessages)->compute(SessionTokens::SHOP, 30);

            $this->assertSame(1, $result->ordersTotal);
            $this->assertSame(1, $result->offerOrders);
            $this->assertSame(1, $result->giftUnits);
            $this->assertSame(1, $result->percentUnits);
            $this->assertEquals(1370.0, $result->totalDiscount);
            $this->assertEquals(140.0, $result->otherMethod['difference']);
            $this->assertEquals([['title' => 'Product B', 'units' => 1, 'value' => 1100.0]], $result->topGifts);
        }
    }

    public function test_orders_whose_products_were_deleted_keep_their_scenario_recompute(): void
    {
        // #4001/#4002 = abc (A paid, B gift, C 30% off), #4003 = A x3, #4004 = ac; every line has product: null.
        foreach ([true, false] as $fromMessages) {
            Cache::flush();
            $this->fake(['orders_deleted_products']);

            $result = $this->app->make(OfferAnalytics::class)->classifyFromMessages($fromMessages)->compute(SessionTokens::SHOP, 30);

            $this->assertSame(4, $result->offerOrders);
            $this->assertEquals(5200.0, $result->totalDiscount);
            $this->assertEquals(280.0, $result->otherMethod['difference']);
            $this->assertSame(4, $result->giftUnits);
            $this->assertSame(3, $result->percentUnits);
            $this->assertNotEmpty($result->topGifts);
            foreach ($result->topGifts as $row) {
                $this->assertSame('Deleted product', $row['title']);
            }
        }
    }

    public function test_orders_query_asks_for_the_range_window(): void
    {
        $fake = $this->fake(['orders_all_rows']);

        $this->api('/api/analytics?range=7')->assertOk();

        $this->assertSame(SessionTokens::SHOP, $fake->calls[0]['shop']);
        $this->assertSame('AnalyticsOrders', $fake->calls[0]['op']);
        $this->assertMatchesRegularExpression('/^created_at:>=\d{4}-\d{2}-\d{2}T/', $fake->calls[0]['variables']['q']);
        $this->assertNull($fake->calls[0]['variables']['cursor']);
    }

    public function test_default_range_is_30(): void
    {
        $this->fake(['orders_all_rows']);

        $this->api()->assertOk()->assertJsonPath('rangeDays', 30);
    }

    public function test_discount_title_only_allocations_are_classified_by_recomputing(): void
    {
        $this->fake(['orders_discount_title']);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('ordersTotal', 1)
            ->assertJsonPath('offerOrders', 1)
            ->assertJsonPath('giftUnits', 1)
            ->assertJsonPath('percentUnits', 1);
        $this->assertEquals(1370.0, $res->json('totalDiscount'));
        $this->assertEquals(140.0, $res->json('otherMethod.difference'));
        $this->assertEquals([['title' => 'Product B', 'units' => 1, 'value' => 1100.0]], $res->json('topGifts'));
    }

    public function test_orders_with_legacy_arabic_allocation_titles_are_still_counted(): void
    {
        // Same orders as orders_all_rows / orders_discount_title, but carrying the pre-English titles.
        $this->fake(['orders_legacy_arabic']);

        $res = $this->api('/api/analytics?range=30')->assertOk();

        $res->assertJsonPath('ordersTotal', 9)
            ->assertJsonPath('offerOrders', 7)
            ->assertJsonPath('giftUnits', 8)
            ->assertJsonPath('percentUnits', 4);
        $this->assertEquals(9370.0, $res->json('totalDiscount'));
        $this->assertEquals(750.0, $res->json('otherMethod.difference'));

        Cache::flush();
        $this->fake(['orders_legacy_arabic_title']);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('offerOrders', 1)
            ->assertJsonPath('giftUnits', 1)
            ->assertJsonPath('percentUnits', 1);
        $this->assertEquals(1370.0, $res->json('totalDiscount'));
    }

    public function test_only_automatic_discount_applications_with_our_titles_are_counted(): void
    {
        // #3001: manual "Gift" + discount code "30% off". #3002: automatic "150% off" (outside 1-99).
        // #3003: genuine title-only order; its numbers must use the default 30%, not 150.
        $this->fake(['orders_foreign_discounts']);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('ordersTotal', 3)
            ->assertJsonPath('offerOrders', 1)
            ->assertJsonPath('giftUnits', 1)
            ->assertJsonPath('percentUnits', 1);
        $this->assertEquals(1370.0, $res->json('totalDiscount'));
        $this->assertEquals([['title' => 'Product B', 'units' => 1, 'value' => 1100.0]], $res->json('topGifts'));
    }

    public function test_the_distinctive_messages_count_after_the_cut_over(): void
    {
        $this->fake([self::pageAt('2026-11-05T10:00:00+00:00',
            [self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'BOGO Gift']]])],
            [self::line(['price' => 1200]), self::line(['price' => 900, 'title' => 'Product C', 'allocs' => [[900, 'هدية العرض']]])],
            [self::line(['price' => 500, 'allocs' => [[150, 'BOGO 30% off']]])],
            [self::line(['price' => 500, 'allocs' => [[150, 'عرض خصم 30%']]])],
        )]);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('offerOrders', 4)->assertJsonPath('giftUnits', 2)->assertJsonPath('percentUnits', 2);
        $this->assertEquals(2300.0, $res->json('totalDiscount'));
    }

    public function test_other_automatic_discounts_are_not_ours_after_the_cut_over(): void
    {
        // A merchant's own automatic "Gift" and "10% off" (and a "BOGO 150% off" outside 1-99) are ignored.
        $this->fake([self::pageAt('2026-11-05T10:00:00+00:00',
            [self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]])],
            [self::line(['price' => 500, 'allocs' => [[50, '10% off']]])],
            [self::line(['price' => 500, 'allocs' => [[750, 'BOGO 150% off']]])],
            [self::line(['price' => 1200]), self::line(['price' => 900, 'title' => 'Product C', 'allocs' => [[900, 'BOGO Gift']]])],
            [self::line(['price' => 1000])],
        )]);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('ordersTotal', 5)->assertJsonPath('offerOrders', 1)
            ->assertJsonPath('giftUnits', 1)->assertJsonPath('percentUnits', 0);
        $this->assertEquals(900.0, $res->json('totalDiscount'));
    }

    public function test_an_ignored_percent_title_does_not_set_the_range_percent(): void
    {
        // The title-only order uses the default 30 for its numbers, not the ignored merchant "10% off".
        $this->fake([self::pageAt('2026-11-05T10:00:00+00:00',
            [self::line(['price' => 500, 'allocs' => [[50, '10% off']]])],
            [self::line(['price' => 1000, 'allocs' => [[300, 'Pairly: BOGO Offers']]])],
        )]);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('offerOrders', 1)->assertJsonPath('percentUnits', 1);
        $this->assertEquals(300.0, $res->json('totalDiscount'));
    }

    public function test_old_titles_only_count_on_orders_before_the_cut_over(): void
    {
        $order = [self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]])];
        $this->fake([self::pageAt('2026-10-31T23:59:59+00:00', $order)]);
        $this->api()->assertOk()->assertJsonPath('giftUnits', 1);

        Cache::flush();
        $this->fake([self::pageAt('2026-11-01T00:00:00+00:00', $order)]);
        $this->api()->assertOk()->assertJsonPath('offerOrders', 0)->assertJsonPath('giftUnits', 0);
    }

    public function test_a_range_mixing_legacy_arabic_and_english_orders_counts_both(): void
    {
        $this->fake(['orders_mixed_legacy_english']);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('offerOrders', 2)
            ->assertJsonPath('giftUnits', 2)
            ->assertJsonPath('percentUnits', 2);
        $this->assertEquals(2740.0, $res->json('totalDiscount'));
    }

    public function test_recompute_path_agrees_with_message_path_on_the_worked_examples(): void
    {
        $this->fake(['orders_all_rows']);

        $result = $this->app->make(OfferAnalytics::class)->classifyFromMessages(false)->compute(SessionTokens::SHOP, 30);

        $this->assertSame(7, $result->offerOrders);
        $this->assertEquals(9370.0, $result->totalDiscount);
        $this->assertSame(8, $result->giftUnits);
        $this->assertSame(4, $result->percentUnits);
        $this->assertEquals(750.0, $result->otherMethod['difference']);
    }

    public function test_second_call_within_ttl_hits_the_client_once(): void
    {
        $fake = $this->fake(['orders_all_rows', 'orders_all_rows']);

        $first = $this->api()->assertOk()->json();
        $second = $this->api()->assertOk()->json();

        $this->assertCount(1, $fake->calls);
        $this->assertSame($first, $second);
        $this->assertTrue(Cache::get('analytics:'.SessionTokens::SHOP.':30')['fingerprint'] === sha1('customer|tag|FallOffer'));
        $this->assertSame(2, $fake->settingsReads);
    }

    public function test_changing_the_tag_recomputes_instead_of_serving_the_cache(): void
    {
        $fake = $this->fake(['orders_all_rows', 'orders_all_rows']);

        $this->api()->assertOk();
        $fake->settingsValue = self::settings(['eligibleTag' => 'WinterOffer']);
        $this->api()->assertOk();

        $this->assertCount(2, $fake->calls);
    }

    public function test_changing_the_method_or_the_collection_recomputes_instead_of_serving_the_cache(): void
    {
        $fake = $this->fake(['orders_all_rows', 'orders_all_rows', 'orders_all_rows']);

        $this->api()->assertOk();
        $fake->settingsValue = self::settings(['method' => 'company']);
        $this->api()->assertOk();
        $fake->settingsValue = self::settings(['method' => 'company', 'appliesTo' => 'collection', 'collectionId' => 'gid://shopify/Collection/9']);
        $this->api()->assertOk();

        $this->assertCount(3, $fake->calls);
        $this->assertTrue(Cache::get('analytics:'.SessionTokens::SHOP.':30')['fingerprint'] === sha1('company|collection|gid://shopify/Collection/9'));
    }

    public function test_recompute_uses_the_tag_from_the_shop_config_not_the_constant(): void
    {
        // Same order as orders_mixed_untagged with FallOffer -> WinterOffer (and falloffer -> winteroffer).
        foreach ([true, false] as $fromMessages) {
            Cache::flush();
            $fake = $this->fake(['orders_mixed_untagged_winter']);
            $fake->settingsValue = self::settings(['eligibleTag' => 'WinterOffer']);

            $result = $this->app->make(OfferAnalytics::class)->classifyFromMessages($fromMessages)->compute(SessionTokens::SHOP, 30);

            $this->assertSame(1, $fake->settingsReads);
            $this->assertSame(1, $result->offerOrders);
            $this->assertSame(1, $result->giftUnits);
            $this->assertSame(1, $result->percentUnits);
            $this->assertEquals(1370.0, $result->totalDiscount);
            $this->assertEquals(140.0, $result->otherMethod['difference']);
            $this->assertEquals([['title' => 'Product B', 'units' => 1, 'value' => 1100.0]], $result->topGifts);
        }
    }

    public function test_lines_tagged_only_with_the_default_tag_are_ineligible_under_another_configured_tag(): void
    {
        // orders_mixed_untagged (FallOffer lines) while the merchant configured WinterOffer: only the two
        // lines carrying our allocations (B 1100, C 900) stay eligible; paid A and the untagged line drop out,
        // so the recompute pairs B with C and derives one gift (C) and no percent unit.
        Cache::flush();
        $fake = $this->fake(['orders_mixed_untagged']);
        $fake->settingsValue = self::settings(['eligibleTag' => 'WinterOffer']);

        $result = $this->app->make(OfferAnalytics::class)->classifyFromMessages(false)->compute(SessionTokens::SHOP, 30);

        $this->assertSame(1, $result->offerOrders);
        $this->assertSame(1, $result->giftUnits);
        $this->assertSame(0, $result->percentUnits);
        $this->assertEquals([['title' => 'Product C', 'units' => 1, 'value' => 900.0]], $result->topGifts);
    }

    public function test_sales_comparison_between_offer_and_other_orders(): void
    {
        $this->fake([self::page(
            [self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]])],
            [self::line(['price' => 500, 'title' => 'Product D', 'allocs' => [[150, '30% off']]])],
            [self::line(['price' => 400, 'qty' => 2, 'tags' => []])],
            [self::line(['price' => 600])],
        )]);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('ordersTotal', 4)->assertJsonPath('offerOrders', 2)
            ->assertJsonPath('unitsOffer', 3)->assertJsonPath('unitsOther', 3)
            ->assertJsonPath('giftUnits', 1)->assertJsonPath('percentUnits', 1);
        $this->assertEquals(50.0, $res->json('offerOrdersPct'));
        $this->assertEquals(1250.0, $res->json('totalDiscount'));
        $this->assertEquals(1550.0, $res->json('offerSales'));
        $this->assertEquals(775.0, $res->json('aovOffer'));
        $this->assertEquals(700.0, $res->json('aovOther'));
        $this->assertSame(44.6, $res->json('discountPctOfSales'));
        $this->assertNull($res->json('profit'));
        $this->assertEqualsCanonicalizing(['currency', 'rangeDays', 'ordersTotal', 'offerOrders', 'offerOrdersPct', 'offerSales', 'totalDiscount',
            'discountPctOfSales', 'aovOffer', 'aovOther', 'unitsOffer', 'unitsOther', 'giftUnits', 'percentUnits', 'otherMethod', 'topGifts',
            'profit', 'capped', 'computedAt'], array_keys($res->json()));
    }

    public function test_aov_other_is_null_without_non_offer_orders(): void
    {
        $this->fake([self::page(
            [self::line(['price' => 1200]), self::line(['price' => 1100, 'allocs' => [[1100, 'Gift']]])],
        )]);

        $res = $this->api()->assertOk();

        $this->assertNull($res->json('aovOther'));
        $this->assertSame(0, $res->json('unitsOther'));
        $this->assertEquals(1200.0, $res->json('aovOffer'));
    }

    public function test_no_offer_orders_gives_null_aov_offer_and_zero_difference(): void
    {
        $this->fake([self::page([self::line(['price' => 600])])]);

        $res = $this->api()->assertOk();

        $this->assertNull($res->json('aovOffer'));
        $this->assertEquals(600.0, $res->json('aovOther'));
        $this->assertEquals(0.0, $res->json('discountPctOfSales'));
        $this->assertEquals(0.0, $res->json('otherMethod.difference'));
    }

    public function test_amounts_use_the_shop_currency_and_its_decimals(): void
    {
        $fake = $this->fake([self::page(
            [self::line(['price' => '12.345']), self::line(['price' => '10.500', 'title' => 'Product B', 'allocs' => [['10.500', 'Gift']]])],
        )]);
        $fake->currency = 'KWD';

        $res = $this->api()->assertOk();

        $this->assertSame('KWD', $res->json('currency'));
        $this->assertSame(10.5, (float) $res->json('totalDiscount'));
        $this->assertSame(12.345, (float) $res->json('offerSales'));
        $this->assertSame(10.5, (float) $res->json('topGifts.0.value'));

        Cache::flush();
        $fake = $this->fake([self::page(
            [self::line(['price' => '1500']), self::line(['price' => '1000', 'title' => 'Product B', 'allocs' => [['1000', 'Gift']]])],
        )]);
        $fake->currency = 'JPY';

        $res = $this->api()->assertOk();

        $this->assertSame('JPY', $res->json('currency'));
        $this->assertEquals(1000, $res->json('totalDiscount'));
        $this->assertEquals(1500, $res->json('offerSales'));
    }

    public function test_every_current_and_legacy_title_is_classified_and_manual_discounts_are_ignored(): void
    {
        $this->fake([self::page(
            [self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]])],
            [self::line(['price' => 1200]), self::line(['price' => 900, 'title' => 'Product C', 'allocs' => [[900, 'هدية']]])],
            [self::line(['price' => 500, 'allocs' => [[150, '30% off']]])],
            [self::line(['price' => 500, 'allocs' => [[150, 'خصم 30%']]])],
            [self::line(['price' => 1200]), self::line(['price' => 800, 'title' => 'Product D', 'allocs' => [[800, 'Pairly: BOGO Offers']]])],
            [self::line(['price' => 1200]), self::line(['price' => 700, 'allocs' => [[700, 'Gift', 'ManualDiscountApplication']]])],
        )]);

        $res = $this->api()->assertOk();

        $res->assertJsonPath('ordersTotal', 6)->assertJsonPath('offerOrders', 5)
            ->assertJsonPath('giftUnits', 3)->assertJsonPath('percentUnits', 2);
        $this->assertEquals(1100.0 + 900 + 150 + 150 + 800, $res->json('totalDiscount'));
    }

    public function test_other_method_is_what_the_other_method_would_have_changed(): void
    {
        // A 1200, B 1100, C 900, D 800 paired by "customer": D and B are gifts, so 2100 is charged.
        // "company" would gift the cheapest two (D and C): 2300 charged, i.e. 200 more for the company.
        $this->fake([self::page([
            self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]]),
            self::line(['price' => 900, 'title' => 'Product C']), self::line(['price' => 800, 'title' => 'Product D', 'allocs' => [[800, 'Gift']]]),
        ])]);

        $res = $this->api()->assertOk();

        $this->assertSame('company', $res->json('otherMethod.method'));
        $this->assertEquals(200.0, $res->json('otherMethod.difference'));

        // Selected "company" on an order charged 2300: customers would have saved 200 more under "customer".
        Cache::flush();
        $fake = $this->fake([self::page([
            self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B']),
            self::line(['price' => 900, 'title' => 'Product C', 'allocs' => [[900, 'Gift']]]), self::line(['price' => 800, 'title' => 'Product D', 'allocs' => [[800, 'Gift']]]),
        ])]);
        $fake->settingsValue = self::settings(['method' => 'company']);

        $res = $this->api()->assertOk();

        $this->assertSame('customer', $res->json('otherMethod.method'));
        $this->assertEquals(200.0, $res->json('otherMethod.difference'));
    }

    public function test_other_method_difference_is_never_negative(): void
    {
        // This order charged 3200 although "company" would have charged 2300 (less discount): it counts 0, not -900.
        $lightlyDiscounted = [
            self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B']),
            self::line(['price' => 900, 'title' => 'Product C']), self::line(['price' => 800, 'title' => 'Product D', 'allocs' => [[800, 'Gift']]]),
        ];
        $positive = [
            self::line(['price' => 1200]), self::line(['price' => 1100, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]]),
            self::line(['price' => 900, 'title' => 'Product C']), self::line(['price' => 800, 'title' => 'Product D', 'allocs' => [[800, 'Gift']]]),
        ];
        $this->fake([self::page($lightlyDiscounted, $positive)]);

        $this->assertEquals(200.0, $this->api()->assertOk()->json('otherMethod.difference'));

        Cache::flush();
        $this->fake([self::page($lightlyDiscounted)]);

        $this->assertEquals(0.0, $this->api()->assertOk()->json('otherMethod.difference'));
    }

    public function test_collection_rule_asks_shopify_and_uses_membership_for_eligibility(): void
    {
        $collection = ['appliesTo' => 'collection', 'collectionId' => 'gid://shopify/Collection/9', 'eligibleTag' => ''];
        // A and B are in the collection; C (5000, in no collection, untagged) is not: leaving C out keeps the pair even.
        $fake = $this->fake([self::page([
            self::line(['price' => 1200, 'tags' => [], 'inCollection' => true]),
            self::line(['price' => 1100, 'tags' => [], 'inCollection' => true, 'title' => 'Product B', 'allocs' => [[1100, 'Pairly: BOGO Offers']]]),
            self::line(['price' => 5000, 'tags' => [], 'inCollection' => false, 'title' => 'Product C']),
        ])]);
        $fake->settingsValue = self::settings($collection);

        $res = $this->api()->assertOk();

        $this->assertStringContainsString('inCollection(id: $collectionId)', $fake->calls[0]['query']);
        $this->assertSame('gid://shopify/Collection/9', $fake->calls[0]['variables']['collectionId']);
        $res->assertJsonPath('offerOrders', 1)->assertJsonPath('giftUnits', 1)->assertJsonPath('percentUnits', 0);
        $this->assertEquals([['title' => 'Product B', 'units' => 1, 'value' => 1100.0]], $res->json('topGifts'));
        $this->assertEquals(0.0, $res->json('otherMethod.difference'));
    }

    public function test_missing_read_orders_scope_answers_403(): void
    {
        $fake = $this->fake([]);
        $fake->ordersDenied = true;

        $this->api()->assertStatus(403)->assertExactJson(['error' => 'needs_read_orders']);
    }

    public function test_profit_is_null_without_cost_access_and_does_not_break_analytics(): void
    {
        $fake = $this->fake([self::page([self::line(['price' => 1200, 'variant' => 'gid://shopify/ProductVariant/1', 'allocs' => [[360, '30% off']]])])]);

        $res = $this->api()->assertOk();

        $this->assertNull($res->json('profit'));
        $this->assertSame(1, $res->json('offerOrders'));
        $this->assertSame([['gid://shopify/ProductVariant/1']], $fake->costCalls);
    }

    public function test_profit_from_unit_costs_with_covered_share(): void
    {
        $v1 = 'gid://shopify/ProductVariant/1';
        $v2 = 'gid://shopify/ProductVariant/2';
        $page = self::page(
            [self::line(['price' => 1200, 'variant' => $v1]), self::line(['price' => 1100, 'variant' => $v2, 'title' => 'Product B', 'allocs' => [[1100, 'Gift']]])],
            [self::line(['price' => 600, 'variant' => 'gid://shopify/ProductVariant/3'])], // not an offer order: never asked for
        );
        $fake = $this->fake([$page]);
        $fake->unitCosts = [$v1 => '700.00', $v2 => '300.00'];

        $res = $this->api()->assertOk();

        // (1200 - 700) + (0 - 300) = 200 over 2 of 2 offer units.
        $this->assertEquals(200.0, $res->json('profit.amount'));
        $this->assertEquals(100.0, $res->json('profit.coveredPct'));
        $this->assertSame([[$v1, $v2]], $fake->costCalls);

        Cache::flush();
        $fake = $this->fake([$page]);
        $fake->unitCosts = [$v1 => '700.00', $v2 => null];

        $res = $this->api()->assertOk();

        $this->assertEquals(500.0, $res->json('profit.amount'));
        $this->assertEquals(50.0, $res->json('profit.coveredPct'));
    }

    public function test_cache_is_per_range(): void
    {
        $fake = $this->fake(['orders_all_rows', 'orders_all_rows']);

        $this->api('/api/analytics?range=7')->assertOk();
        $this->api('/api/analytics?range=60')->assertOk();

        $this->assertCount(2, $fake->calls);
    }

    public function test_cap_stops_pagination_and_flags_capped(): void
    {
        $fake = $this->fake(['orders_page1', 'orders_page2']);

        $out = $this->app->make(OrdersFetcher::class)->fetch(SessionTokens::SHOP, 30, 3);

        $this->assertCount(3, $out['orders']);
        $this->assertTrue($out['capped']);
        $this->assertCount(2, $fake->calls);
        $this->assertSame('CUR1', $fake->calls[1]['variables']['cursor']);
    }

    public function test_cap_equal_to_total_is_not_capped(): void
    {
        $this->fake(['orders_page1', 'orders_page2']);

        $out = $this->app->make(OrdersFetcher::class)->fetch(SessionTokens::SHOP, 30, 4);

        $this->assertCount(4, $out['orders']);
        $this->assertFalse($out['capped']);
    }

    public function test_cap_reached_on_the_first_page_does_not_fetch_more(): void
    {
        $fake = $this->fake(['orders_page1', 'orders_page2']);

        $out = $this->app->make(OrdersFetcher::class)->fetch(SessionTokens::SHOP, 30, 2);

        $this->assertCount(2, $out['orders']);
        $this->assertTrue($out['capped']);
        $this->assertCount(1, $fake->calls);
    }

    public function test_range_validation(): void
    {
        $fake = $this->fake([]);

        foreach (['90', '0', 'abc', '30.5', '-7'] as $bad) {
            $this->api("/api/analytics?range={$bad}")->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        }
        $this->assertSame([], $fake->calls);
    }

    public function test_eleventh_request_in_a_minute_is_rate_limited(): void
    {
        $this->fake(['orders_all_rows']);

        for ($i = 0; $i < 10; $i++) {
            $this->api()->assertOk();
        }
        $this->api()->assertStatus(429);
    }

    /** Security review F3: the limiter is keyed by the verified shop, so one shop cannot exhaust another's budget. */
    public function test_rate_limit_is_per_shop_not_per_ip(): void
    {
        $this->fake(['orders_all_rows', 'orders_all_rows']);
        $other = 'levoilest.myshopify.com';
        Shop::create(['shop_domain' => $other, 'access_token' => 'shpat_other', 'scopes' => 'read_orders', 'installed_at' => now()]);

        for ($i = 0; $i < 10; $i++) {
            $this->api()->assertOk();
        }
        $this->api()->assertStatus(429);

        $otherToken = SessionTokens::mint(['iss' => "https://{$other}/admin", 'dest' => "https://{$other}"]);
        $this->withHeader('Authorization', 'Bearer '.$otherToken)->getJson('/api/analytics')->assertOk();
    }

    /** Security review F3: unauthenticated calls are rejected before the limiter and cannot lock a shop out. */
    public function test_unauthenticated_requests_do_not_consume_the_shop_rate_limit(): void
    {
        $this->fake(['orders_all_rows']);

        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/analytics')->assertStatus(401);
        }

        $this->api()->assertOk();
    }

    public function test_requires_session_token(): void
    {
        $this->getJson('/api/analytics')->assertStatus(401);
    }
}
