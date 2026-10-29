<?php

namespace Tests\Unit;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.allowed_shops' => ['levoilestores.myshopify.com', 'levoilest.myshopify.com']]);
    }

    public function test_allowed_shop_is_accepted_case_insensitively(): void
    {
        $this->assertTrue(Shop::isAllowed('levoilestores.myshopify.com'));
        $this->assertTrue(Shop::isAllowed('  LeVoileStores.MyShopify.com '));
    }

    public function test_unknown_or_malformed_domains_are_rejected(): void
    {
        $this->assertFalse(Shop::isAllowed('evil.myshopify.com'));
        $this->assertFalse(Shop::isAllowed(''));
        $this->assertFalse(Shop::isAllowed('https://levoilestores.myshopify.com'));
        $this->assertFalse(Shop::isAllowed('levoilestores.myshopify.com/admin'));
        $this->assertFalse(Shop::isAllowed('levoilestores.myshopify.com.evil.com'));
    }

    public function test_access_token_is_encrypted_at_rest_and_round_trips(): void
    {
        Shop::create([
            'shop_domain' => 'levoilestores.myshopify.com',
            'access_token' => 'shpat_plain_token',
            'scopes' => 'read_products',
            'installed_at' => now(),
        ]);

        $raw = DB::table('shops')->where('shop_domain', 'levoilestores.myshopify.com')->value('access_token');
        $this->assertNotSame('shpat_plain_token', $raw);
        $this->assertStringNotContainsString('shpat_plain_token', $raw);
        $this->assertSame('shpat_plain_token', Shop::first()->access_token);
    }

    public function test_active_returns_only_installed_shops(): void
    {
        Shop::create(['shop_domain' => 'levoilestores.myshopify.com', 'access_token' => 't', 'scopes' => '', 'installed_at' => now()]);
        Shop::create(['shop_domain' => 'levoilest.myshopify.com', 'access_token' => 't', 'scopes' => '', 'installed_at' => now(), 'uninstalled_at' => now()]);

        $this->assertNotNull(Shop::active('LEVOILESTORES.myshopify.com'));
        $this->assertNull(Shop::active('levoilest.myshopify.com'));
        $this->assertNull(Shop::active('missing.myshopify.com'));
    }
}
