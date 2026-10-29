<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SessionTokens;
use Tests\TestCase;

/**
 * Two first requests race to install the same shop. Uses DatabaseMigrations (no wrapping
 * transaction) so the competing insert is really committed, as it would be in production.
 */
class AuthMiddlewareInstallRaceTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Shop::flushEventListeners();
        parent::tearDown();
    }

    public function test_concurrent_first_install_losing_unique_race_still_returns_200(): void
    {
        Http::fake(['https://levoilestores.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_ours', 'scope' => 'write_discounts'])]);

        // Simulate the competing request committing its row between our read and our insert.
        Shop::creating(function () {
            DB::table('shops')->insert([
                'shop_domain' => SessionTokens::SHOP,
                'access_token' => encrypt('shpat_theirs', false),
                'scopes' => 'write_discounts',
                'installed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $res = $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint())->getJson('/api/ping');

        $res->assertOk()->assertExactJson(['ok' => true, 'shop' => SessionTokens::SHOP]);
        $this->assertSame(1, Shop::count());
        $this->assertNotNull(Shop::active(SessionTokens::SHOP));
    }
}
