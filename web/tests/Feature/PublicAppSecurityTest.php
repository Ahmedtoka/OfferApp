<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Analytics\OfferAnalytics;
use App\Shopify\GraphqlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeOrdersClient;
use Tests\Support\SessionTokens;
use Tests\TestCase;

/** Regression tests from docs/security/2026-10-31-review.md. */
class PublicAppSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://pairly.test';

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        config(['shopify.site_url' => self::SITE, 'shopify.app_url' => self::SITE, 'shopify.allowed_shops' => []]);
    }

    /** F1: anonymous traffic must not write a session row or set a cookie in the embedded iframe. */
    public function test_public_and_embedded_responses_are_stateless(): void
    {
        config(['session.driver' => 'database']);

        $query = ['shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'];
        $admin = self::SITE.'/?'.http_build_query($query + ['hmac' => SessionTokens::signQuery($query)]);

        $responses = [
            $this->get(self::SITE.'/')->assertOk(),
            $this->get(self::SITE.'/pricing')->assertOk(),
            $this->get(self::SITE.'/ar/privacy')->assertOk(),
            $this->get($admin)->assertOk(),
            $this->postJson(self::SITE.'/webhooks', [])->assertStatus(401),
            $this->get(self::SITE.'/no-such-page')->assertNotFound(),
        ];

        foreach ($responses as $response) {
            $this->assertSame([], $response->headers->getCookies());
        }
        $this->assertSame(0, DB::table('sessions')->count());
    }

    private function api(string $shop = SessionTokens::SHOP)
    {
        Shop::firstOrCreate(['shop_domain' => $shop], ['access_token' => 'shpat_x', 'installed_at' => now()]);

        return $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint(['iss' => "https://{$shop}/admin", 'dest' => "https://{$shop}"]));
    }

    /** F2: with open installs one store must not be able to tie up the workers every other store shares. */
    public function test_shop_api_routes_are_throttled_per_shop(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->api()->getJson('/api/ping')->assertOk();
        }

        $this->api()->getJson('/api/ping')->assertStatus(429);
        $this->api('another-store.myshopify.com')->getJson('/api/ping')->assertOk();
    }

    private function webhook(string $topic, string $body, string $shop = SessionTokens::SHOP)
    {
        return $this->call('POST', self::SITE.'/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, SessionTokens::API_SECRET, true)),
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
        ], $body);
    }

    /** F3: the erase request covers the shop's sales figures in the analytics cache, not only its row. */
    public function test_shop_redact_erases_the_shops_cached_analytics_only(): void
    {
        $other = 'another-store.myshopify.com';
        foreach ([SessionTokens::SHOP, $other] as $shop) {
            $fake = new FakeOrdersClient(['orders_all_rows']);
            $fake->settingsValue = json_encode(['enabled' => true, 'percent' => 30, 'method' => 'customer', 'appliesTo' => 'tag', 'eligibleTag' => 'FallOffer', 'collectionId' => null]);
            $this->app->instance(GraphqlClient::class, $fake);
            $this->api($shop)->getJson('/api/analytics?range=30')->assertOk();
        }
        $this->assertTrue(Cache::has(OfferAnalytics::cacheKey(SessionTokens::SHOP, 30)));

        $this->webhook('shop/redact', json_encode(['shop_id' => 1, 'shop_domain' => SessionTokens::SHOP]))->assertOk();

        $this->assertFalse(Cache::has(OfferAnalytics::cacheKey(SessionTokens::SHOP, 30)));
        $this->assertTrue(Cache::has(OfferAnalytics::cacheKey($other, 30)));
    }

    /** F4: the shop header is not signed, so a signed body without the shop's domain cannot uninstall a shop. */
    public function test_a_signed_body_naming_no_shop_cannot_uninstall_any_shop(): void
    {
        $shop = Shop::create(['shop_domain' => SessionTokens::SHOP, 'access_token' => 'shpat_victim', 'installed_at' => now()]);

        // A genuine payload of another topic, replayed with the topic and shop headers swapped.
        $this->webhook('app/uninstalled', json_encode(['shop_id' => 1, 'customer' => ['id' => 2]]))->assertOk();

        $shop->refresh();
        $this->assertNull($shop->uninstalled_at);
        $this->assertSame('shpat_victim', $shop->access_token);
    }

    /** F6: the repo is public. Real Shopify token shapes and written-down store passwords stay out of it. */
    public function test_no_credentials_are_tracked_in_the_repo(): void
    {
        $pattern = 'shp(at|ss|ca|pa|ua)_[a-fA-F0-9]{32}|visitor password [`"\'][^`"\']+[`"\']';
        $root = escapeshellarg(base_path('..'));
        exec("git -C {$root} rev-parse --is-inside-work-tree 2>&1", $inside, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Not a git checkout.');
        }

        exec('git -C '.$root.' grep -n -I -E '.escapeshellarg($pattern).' -- . 2>&1', $hits);

        $this->assertSame([], array_values(array_filter($hits, fn ($line) => ! str_contains($line, 'PublicAppSecurityTest.php'))));
    }
}
