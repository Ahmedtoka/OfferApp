<?php

namespace Tests\Feature;

use App\Offer\OfferConstants;
use Illuminate\Testing\TestResponse;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
    }

    private function loadAdminPage(): TestResponse
    {
        $params = ['embedded' => '1', 'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvbGV2b2lsZXN0b3Jlcw', 'shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'];
        $params['hmac'] = SessionTokens::signQuery($params);

        return $this->get('/?'.http_build_query($params));
    }

    public function test_page_is_english_ltr_with_app_bridge_and_api_key_meta(): void
    {
        $res = $this->loadAdminPage();

        $res->assertOk();
        $res->assertSee('<html lang="en" dir="ltr">', false);
        $res->assertSee('<meta name="shopify-api-key" content="'.SessionTokens::API_KEY.'">', false);
        $res->assertSee('<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>', false);
        $res->assertSee('<script src="https://cdn.shopify.com/shopifycloud/polaris-2.js"></script>', false);
        $res->assertSee('src="/app.js', false);
        $res->assertSee('href="/app.css', false);
    }

    public function test_page_renders_settings_and_analytics_copy(): void
    {
        $res = $this->loadAdminPage();

        $res->assertOk();
        foreach ([
            'Settings', 'Numbers',
            'Offer is ON', 'Offer is OFF', 'Discount on a single piece (%)', 'Save',
            'Discount exists and is active in Shopify', 'Open in Discounts', 'No discount yet — click Save to create it',
            '7 days', '30 days', '60 days',
            'Orders with the offer', '% of all orders', 'Gifts', '30% off pieces', 'Total discount',
            'Scenario 2 would have earned extra',
            'Recalculated on the same orders; even-count orders contribute 0; original price = current compare-at price. Refunded orders are counted as granted; cancelled orders are excluded.',
            'Computed on the first 2,000 orders',
            'Top 10 gifted products', 'Product', 'Pieces', 'Value',
            'Loading…', 'No orders in this period',
        ] as $text) {
            $res->assertSee($text, false);
        }
    }

    public function test_tag_field_defaults_to_the_shared_constant_with_the_note_under_it(): void
    {
        $res = $this->loadAdminPage();

        $res->assertOk();
        $this->assertSame('FallOffer', OfferConstants::get('eligibleTag'));
        $res->assertSee('Eligible product tag', false);
        $res->assertSee('Applies only to products tagged "FallOffer".', false);
        $res->assertSeeInOrder(['id="percent"', 'id="eligible-tag"', 'value="FallOffer"', 'maxLength="100"', 'Applies only to products tagged'], false);
        // app.js re-renders the note live from this template as the merchant types.
        $res->assertSee('data-template=\'Applies only to products tagged "{tag}".\'', false);
    }

    public function test_app_js_sends_and_renders_the_tag(): void
    {
        $js = (string) file_get_contents(public_path('app.js'));

        $this->assertStringContainsString("{ enabled: Boolean(els.enabled.checked), percent, eligibleTag }", $js);
        $this->assertStringContainsString("els.tag.addEventListener('input'", $js);
        $this->assertStringContainsString('settings.eligibleTag', $js);
    }

    public function test_app_js_retries_a_failed_get_once_but_never_a_put(): void
    {
        $js = (string) file_get_contents(public_path('app.js'));

        $this->assertStringContainsString('const RETRY_DELAY_MS = 800;', $js);
        $this->assertStringContainsString('const RETRYABLE_STATUSES = [401, 502, 503, 504];', $js);
        $this->assertStringContainsString("if (method !== 'GET' || !(e instanceof RetryableError))", $js);
    }

    public function test_range_options_default_to_30(): void
    {
        $res = $this->loadAdminPage();

        $res->assertSee('value="30"', false);
        foreach (['7', '30', '60'] as $days) {
            $res->assertSee('<s-option value="'.$days.'"', false);
        }
    }

    public function test_page_never_contains_the_api_secret(): void
    {
        config(['shopify.api_secret' => 'shpss_dummy_secret_for_home_test']);
        $params = ['shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'];
        $params['hmac'] = hash_hmac('sha256', 'shop='.SessionTokens::SHOP.'&timestamp=1700000000', 'shpss_dummy_secret_for_home_test');

        $res = $this->get('/?'.http_build_query($params));

        $res->assertOk();
        $this->assertStringNotContainsString('shpss_dummy_secret_for_home_test', $res->getContent());
        $this->assertStringNotContainsString((string) config('shopify.api_secret'), $res->getContent());
    }

    public function test_page_without_hmac_is_401(): void
    {
        $this->get('/')->assertStatus(401);
    }
}
