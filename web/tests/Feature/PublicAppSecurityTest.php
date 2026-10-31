<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
