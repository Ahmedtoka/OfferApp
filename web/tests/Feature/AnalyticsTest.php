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
        $this->app->instance(GraphqlClient::class, $fake);

        return $fake;
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
        $this->assertEquals(390.0, $res->json('scenario2Extra'));
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
        $this->assertEquals(390.0, $res->json('scenario2Extra'));
        $this->assertNotContains('Product Cancelled', array_column($res->json('topGifts'), 'title'));
        $this->assertSame('Product B', $res->json('topGifts.0.title'));
    }

    public function test_orders_query_excludes_cancelled_orders(): void
    {
        $fake = $this->fake(['orders_all_rows']);

        $this->api('/api/analytics?range=30')->assertOk();

        $this->assertStringContainsString('-status:cancelled', $fake->calls[0]['variables']['q']);
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
        $this->assertEquals(140.0, $res->json('scenario2Extra'));
        $this->assertEquals([['title' => 'Product B', 'units' => 1, 'value' => 1100.0]], $res->json('topGifts'));
    }

    public function test_recompute_path_agrees_with_message_path_on_the_worked_examples(): void
    {
        $this->fake(['orders_all_rows']);

        $result = $this->app->make(OfferAnalytics::class)->classifyFromMessages(false)->compute(SessionTokens::SHOP, 30);

        $this->assertSame(7, $result->offerOrders);
        $this->assertEquals(9370.0, $result->totalDiscount);
        $this->assertSame(8, $result->giftUnits);
        $this->assertSame(4, $result->percentUnits);
        $this->assertEquals(390.0, $result->scenario2Extra);
    }

    public function test_second_call_within_ttl_hits_the_client_once(): void
    {
        $fake = $this->fake(['orders_all_rows', 'orders_all_rows']);

        $first = $this->api()->assertOk()->json();
        $second = $this->api()->assertOk()->json();

        $this->assertCount(1, $fake->calls);
        $this->assertSame($first, $second);
        $this->assertTrue(Cache::has('analytics:'.SessionTokens::SHOP.':30'));
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
