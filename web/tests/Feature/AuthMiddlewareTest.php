<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class AuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const EXCHANGE_URL = 'https://levoilestores.myshopify.com/admin/oauth/access_token';

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        Http::preventStrayRequests();
    }

    private function installShop(): Shop
    {
        return Shop::create([
            'shop_domain' => SessionTokens::SHOP,
            'access_token' => 'shpat_existing',
            'scopes' => 'write_discounts',
            'installed_at' => now(),
        ]);
    }

    private function assertEmpty401($res): void
    {
        $res->assertStatus(401);
        $this->assertSame('{}', $res->getContent());
    }

    public function test_missing_bearer_returns_401_with_empty_object(): void
    {
        $this->assertEmpty401($this->getJson('/api/ping'));
    }

    public function test_bad_bearer_returns_401_with_empty_object(): void
    {
        foreach (['garbage', SessionTokens::mint([], null, 'wrong-secret'), SessionTokens::mint(['aud' => 'x'])] as $token) {
            $this->assertEmpty401($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/ping'));
        }
    }

    public function test_session_cookie_does_not_authenticate_api(): void
    {
        $this->installShop();

        $this->assertEmpty401($this->withCookie('laravel_session', 'anything')->getJson('/api/ping'));
    }

    public function test_valid_token_for_installed_shop_passes_without_exchange(): void
    {
        $this->installShop();
        Http::fake();

        $res = $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint())->getJson('/api/ping');

        $res->assertOk()->assertExactJson(['ok' => true, 'shop' => SessionTokens::SHOP]);
        Http::assertNothingSent();
    }

    public function test_valid_token_for_uninstalled_allowed_shop_exchanges_once_and_installs(): void
    {
        Http::fake([self::EXCHANGE_URL => Http::response(['access_token' => 'shpat_new', 'scope' => 'write_discounts,read_products'])]);
        $jwt = SessionTokens::mint();

        $res = $this->withHeader('Authorization', "Bearer {$jwt}")->getJson('/api/ping');

        $res->assertOk()->assertExactJson(['ok' => true, 'shop' => SessionTokens::SHOP]);
        $this->assertStringNotContainsString('shpat_new', $res->getContent());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->url() === self::EXCHANGE_URL
            && $r->method() === 'POST'
            && $r['client_id'] === SessionTokens::API_KEY
            && $r['client_secret'] === SessionTokens::API_SECRET
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $r['subject_token'] === $jwt
            && $r['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
            && $r['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token');

        $shop = Shop::active(SessionTokens::SHOP);
        $this->assertNotNull($shop);
        $this->assertSame('shpat_new', $shop->access_token);
        $this->assertSame('write_discounts,read_products', $shop->scopes);
    }

    public function test_reinstall_reactivates_previously_uninstalled_row(): void
    {
        $this->installShop()->update(['uninstalled_at' => now()]);
        Http::fake([self::EXCHANGE_URL => Http::response(['access_token' => 'shpat_again', 'scope' => 'write_discounts'])]);

        $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint())->getJson('/api/ping')->assertOk();

        $this->assertSame(1, Shop::count());
        $this->assertSame('shpat_again', Shop::active(SessionTokens::SHOP)->access_token);
    }

    public function test_failed_exchange_returns_401_and_creates_nothing(): void
    {
        Http::fake([self::EXCHANGE_URL => Http::response(['error' => 'invalid_subject_token'], 400)]);

        $this->assertEmpty401($this->withHeader('Authorization', 'Bearer '.SessionTokens::mint())->getJson('/api/ping'));
        $this->assertSame(0, Shop::count());
    }

    public function test_admin_load_with_bad_hmac_is_401(): void
    {
        $this->assertEmpty401($this->get('/?shop=levoilestores.myshopify.com&timestamp=1&hmac=bad'));
    }

    public function test_admin_load_without_hmac_or_id_token_is_401(): void
    {
        $this->assertEmpty401($this->get('/?shop=levoilestores.myshopify.com'));
    }

    public function test_admin_load_with_valid_hmac_for_disallowed_shop_is_401(): void
    {
        $params = ['shop' => 'evil.myshopify.com', 'timestamp' => '1700000000'];
        $params['hmac'] = SessionTokens::signQuery($params);

        $this->assertEmpty401($this->get('/?'.http_build_query($params)));
    }

    public function test_admin_load_with_valid_hmac_for_any_shop_is_200_when_allow_list_is_empty(): void
    {
        config(['shopify.allowed_shops' => []]);
        $params = ['shop' => 'any-store.myshopify.com', 'timestamp' => '1700000000'];
        $params['hmac'] = SessionTokens::signQuery($params);

        $this->get('/?'.http_build_query($params))->assertOk();
    }

    public function test_admin_load_with_bad_hmac_is_401_when_allow_list_is_empty(): void
    {
        config(['shopify.allowed_shops' => []]);

        $this->assertEmpty401($this->get('/?shop=any-store.myshopify.com&timestamp=1&hmac=bad'));
    }

    public function test_admin_load_with_correct_hmac_is_200_with_shop_csp(): void
    {
        $params = ['embedded' => '1', 'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvbGV2b2lsZXN0b3Jlcw', 'locale' => 'en', 'shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'];
        $params['hmac'] = SessionTokens::signQuery($params);

        $res = $this->get('/?'.http_build_query($params));

        $res->assertOk();
        $this->assertSame(
            'frame-ancestors https://levoilestores.myshopify.com https://admin.shopify.com;',
            $res->headers->get('Content-Security-Policy')
        );
        $this->assertFalse($res->headers->has('X-Frame-Options'));
    }

    public function test_admin_load_hmac_excludes_signature_param(): void
    {
        $params = ['shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'];
        $params['hmac'] = SessionTokens::signQuery($params);
        $params['signature'] = 'legacy-signature-value';

        $this->get('/?'.http_build_query($params))->assertOk();
    }

    public function test_admin_load_with_valid_id_token_is_200(): void
    {
        $res = $this->get('/?'.http_build_query(['shop' => SessionTokens::SHOP, 'id_token' => SessionTokens::mint()]));

        $res->assertOk();
        $this->assertStringContainsString('https://levoilestores.myshopify.com', $res->headers->get('Content-Security-Policy'));
    }

    public function test_admin_load_with_id_token_for_other_shop_param_is_401(): void
    {
        $this->assertEmpty401($this->get('/?'.http_build_query(['shop' => 'levoilest.myshopify.com', 'id_token' => SessionTokens::mint()])));
    }

    public function test_admin_load_with_invalid_id_token_is_401(): void
    {
        $this->assertEmpty401($this->get('/?'.http_build_query(['shop' => SessionTokens::SHOP, 'id_token' => SessionTokens::mint(['aud' => 'x'])])));
    }

    public function test_rejected_load_gets_frame_ancestors_none(): void
    {
        $res = $this->get('/?shop=levoilestores.myshopify.com&hmac=bad');

        $this->assertSame("frame-ancestors 'none';", $res->headers->get('Content-Security-Policy'));
    }

    /** Security review F2: responses outside the web/api groups (health, 404, 405) must not be frameable either. */
    public function test_responses_outside_route_groups_get_frame_ancestors_none(): void
    {
        foreach ([$this->get('/up'), $this->get('/no-such-page'), $this->get('/api/no-such-endpoint'), $this->post('/')] as $res) {
            $this->assertSame("frame-ancestors 'none';", $res->headers->get('Content-Security-Policy'));
            $this->assertFalse($res->headers->has('X-Frame-Options'));
        }
    }
}
