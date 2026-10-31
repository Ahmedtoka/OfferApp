<?php

namespace Tests\Feature;

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

    private function loadAdminPage(array $extra = [], string $path = '/'): TestResponse
    {
        $params = ['embedded' => '1', 'host' => 'YWRtaW4uc2hvcGlmeS5jb20vc3RvcmUvbGV2b2lsZXN0b3Jlcw', 'shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'] + $extra;
        $params['hmac'] = SessionTokens::signQuery($params);

        return $this->get($path.'?'.http_build_query($params));
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

    public function test_arabic_locale_is_rtl_with_arabic_strings(): void
    {
        foreach (['ar', 'ar-EG', 'ar_SA'] as $locale) {
            $res = $this->loadAdminPage(['locale' => $locale]);

            $res->assertOk();
            $res->assertSee('<html lang="ar" dir="rtl">', false);
            $res->assertSee('>'.trans('app.nav.settings', [], 'ar').'</s-link>', false);
            $res->assertDontSee('>'.trans('app.nav.settings', [], 'en').'</s-link>', false);
        }
    }

    public function test_other_locales_fall_back_to_english_ltr(): void
    {
        foreach (['en-GB', 'fr', 'zz'] as $locale) {
            $this->loadAdminPage(['locale' => $locale])->assertOk()->assertSee('<html lang="en" dir="ltr">', false);
        }
    }

    public function test_lang_files_have_identical_keys_and_no_empty_values(): void
    {
        $flatten = function (array $a, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $out += $flatten($v, $prefix.$k.'.') : $out[$prefix.$k] = $v;
            }

            return $out;
        };
        $en = $flatten(require base_path('lang/en/app.php'));
        $ar = $flatten(require base_path('lang/ar/app.php'));

        $this->assertSame(array_keys($en), array_keys($ar));
        foreach ($ar as $key => $value) {
            $this->assertNotSame('', trim((string) $value), $key);
            $this->assertNotSame($en[$key], $value, "ar.$key is still English");
        }
    }

    public function test_no_other_store_branding_anywhere(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->loadAdminPage(['locale' => $locale])->getContent();
            foreach (['Le Voile', 'FallOffer', 'EGP'] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "$locale page");
            }
        }
        foreach (['public/app.js', 'public/app.css', 'public/js/lib.js', 'lang/en/app.php', 'lang/ar/app.php', 'resources/views/home.blade.php'] as $file) {
            foreach (['Le Voile', 'FallOffer', 'EGP'] as $needle) {
                $this->assertStringNotContainsString($needle, (string) file_get_contents(base_path($file)), $file);
            }
        }
    }

    public function test_title_comes_from_the_app_name_config(): void
    {
        config(['shopify.app_name' => 'Pairly: Test Name']);

        $this->loadAdminPage()->assertSee('<title>Pairly: Test Name</title>', false);
    }

    public function test_strings_are_passed_to_js_as_a_json_block_in_the_page_language(): void
    {
        $res = $this->loadAdminPage(['locale' => 'ar']);
        $res->assertSee('<script type="application/json" id="i18n">', false);
        preg_match('#<script type="application/json" id="i18n">(.*?)</script>#s', $res->getContent(), $m);
        $strings = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ar', $strings['locale']);
        $this->assertSame(trans('app.errors.generic', [], 'ar'), $strings['t']['errors']['generic']);
    }

    public function test_page_has_nav_pages_and_settings_controls(): void
    {
        $res = $this->loadAdminPage();

        $res->assertSee('<s-app-nav>', false);
        foreach (['href="/"', 'href="/settings"', 'href="/analytics"', 'href="/test"'] as $href) {
            $res->assertSee($href, false);
        }
        foreach ([
            'id="page-home"', 'id="page-settings"', 'id="page-analytics"', 'id="page-test"',
            'id="enabled"', 'id="percent"', 'id="method"', 'id="applies-to"', 'id="eligible-tag"', 'id="collection-pick"',
            'id="starts-at"', 'id="ends-at"', 'id="coupons"', 'id="save"', 'id="state-line"', 'id="billing-banner"', 'id="checklist"',
        ] as $id) {
            $res->assertSee($id, false);
        }
        // No default tag in the public app; method defaults to customer.
        $res->assertSeeInOrder(['id="eligible-tag"', 'value=""'], false);
        $res->assertSee('<s-option value="customer"', false);
        $res->assertSee('<s-option value="company"', false);
    }

    public function test_every_page_path_serves_the_same_shell(): void
    {
        foreach (['/settings', '/analytics', '/test'] as $path) {
            $this->loadAdminPage([], $path)->assertOk()->assertSee('id="page-settings"', false);
        }
    }

    public function test_app_js_keeps_the_retry_and_billing_rules(): void
    {
        $js = (string) file_get_contents(public_path('app.js'));

        $this->assertStringContainsString('const RETRY_DELAY_MS = 800;', $js);
        $this->assertStringContainsString('const RETRYABLE_STATUSES = [401, 502, 503, 504];', $js);
        $this->assertStringContainsString("if (method !== 'GET' || !(e instanceof RetryableError))", $js);
        $this->assertStringContainsString("'_top'", $js);
        $this->assertStringContainsString("resourcePicker({ type: 'collection'", $js);
        $this->assertStringNotContainsString('Could not reach', $js);
    }

    public function test_range_options_default_to_30(): void
    {
        $res = $this->loadAdminPage();

        $res->assertSee('value="30"', false);
        foreach (['7', '30', '60'] as $days) {
            $res->assertSee('<s-option value="'.$days.'"', false);
        }
    }

    public function test_theme_embed_link_parts_are_passed_to_js(): void
    {
        $res = $this->loadAdminPage();

        $res->assertSee('data-theme-handle="app-embed"', false);
        $res->assertSee('data-api-key="'.SessionTokens::API_KEY.'"', false);
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
