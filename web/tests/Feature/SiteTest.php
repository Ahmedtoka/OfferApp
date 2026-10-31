<?php

namespace Tests\Feature;

use Tests\Support\SessionTokens;
use Tests\TestCase;

class SiteTest extends TestCase
{
    private const PAGES = ['/', '/pricing', '/docs', '/privacy', '/terms', '/ar', '/ar/pricing', '/ar/docs', '/ar/privacy', '/ar/terms'];

    private const SITE = 'https://pairly.test';

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        // The test client's host becomes the site host, whatever the local .env says.
        config(['shopify.site_url' => self::SITE, 'shopify.app_url' => self::SITE]);
    }

    private function page(string $path): string
    {
        return (string) $this->get(self::SITE.$path)->assertOk()->getContent();
    }

    public function test_every_page_is_public_in_both_languages_with_the_right_direction(): void
    {
        foreach (self::PAGES as $path) {
            $html = $this->page($path);
            $arabic = str_starts_with($path, '/ar');
            $this->assertStringContainsString($arabic ? '<html lang="ar" dir="rtl">' : '<html lang="en" dir="ltr">', $html, $path);
            $this->assertMatchesRegularExpression('/<h1[ >]/', $html, $path);
        }
    }

    public function test_arabic_pages_are_written_in_arabic(): void
    {
        foreach (['/ar', '/ar/pricing', '/ar/docs', '/ar/privacy', '/ar/terms'] as $path) {
            $text = strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $this->page($path)));
            preg_match_all('/\p{Arabic}/u', $text, $arabic);
            preg_match_all('/\p{Latin}/u', $text, $latin);
            $this->assertGreaterThan(count($latin[0]) * 4, count($arabic[0]), $path);
        }
    }

    public function test_marketing_pages_cannot_be_framed(): void
    {
        foreach (self::PAGES as $path) {
            $this->assertSame("frame-ancestors 'none';", $this->get(self::SITE.$path)->headers->get('Content-Security-Policy'), $path);
        }
    }

    public function test_root_with_a_valid_hmac_is_still_the_embedded_app(): void
    {
        $params = ['shop' => SessionTokens::SHOP, 'timestamp' => '1700000000'];
        $params['hmac'] = SessionTokens::signQuery($params);
        $res = $this->get(self::SITE.'/?'.http_build_query($params))->assertOk();

        $res->assertSee('name="shopify-api-key"', false);
        $res->assertDontSee('site.css');
        $this->assertStringContainsString(SessionTokens::SHOP, (string) $res->headers->get('Content-Security-Policy'));
    }

    public function test_root_with_a_valid_id_token_is_still_the_embedded_app(): void
    {
        $this->get(self::SITE.'/?'.http_build_query(['shop' => SessionTokens::SHOP, 'id_token' => SessionTokens::mint()]))
            ->assertOk()->assertSee('name="shopify-api-key"', false);
    }

    public function test_root_with_a_bad_hmac_or_id_token_stays_401_and_never_falls_back_to_the_landing(): void
    {
        foreach (['/?hmac=bad', '/?shop=x.myshopify.com&timestamp=1&hmac=bad', '/?hmac[]=x', '/?hmac=', '/?id_token=bad', '/?id_token[]=x', '/?id_token='] as $query) {
            $res = $this->get(self::SITE.$query);
            $res->assertStatus(401);
            $res->assertDontSee('site.css');
        }
    }

    public function test_root_without_auth_on_another_host_stays_401(): void
    {
        config(['shopify.site_url' => 'https://www.other-host.test']);
        $this->get(self::SITE.'/')->assertStatus(401);
    }

    public function test_site_host_falls_back_to_the_app_url_until_a_domain_exists(): void
    {
        config(['shopify.site_url' => null]);
        $this->get(self::SITE.'/')->assertOk()->assertSee('site.css');

        config(['shopify.site_url' => '', 'shopify.app_url' => 'https://other-app.test']);
        $this->get(self::SITE.'/')->assertStatus(401);
    }

    public function test_site_host_match_ignores_case_scheme_and_port(): void
    {
        config(['shopify.site_url' => 'http://PAIRLY.test:8080/']);
        $this->get(self::SITE.'/')->assertOk()->assertSee('site.css');
    }

    public function test_embedded_client_paths_stay_behind_auth(): void
    {
        foreach (['/settings', '/analytics', '/test'] as $path) {
            $this->get(self::SITE.$path)->assertStatus(401);
        }
    }

    public function test_price_and_trial_are_stated(): void
    {
        foreach (['/', '/pricing', '/ar', '/ar/pricing'] as $path) {
            $html = $this->page($path);
            $this->assertStringContainsString('$19.99', $html, $path);
            $this->assertMatchesRegularExpression('/\b14\b/', $html, $path);
        }
    }

    public function test_landing_has_the_spec_sections(): void
    {
        foreach (['/', '/ar'] as $path) {
            $html = $this->page($path);
            foreach (['id="rule"', 'id="compare"', 'id="pricing"', 'id="faq"', 'data-install'] as $needle) {
                $this->assertStringContainsString($needle, $html, $path);
            }
        }
    }

    public function test_docs_cover_setup_tag_or_collection_test_theme_pricing_faq(): void
    {
        foreach (['/docs', '/ar/docs'] as $path) {
            $html = $this->page($path);
            foreach (['setup', 'products', 'test', 'theme', 'pricing', 'faq'] as $id) {
                $this->assertStringContainsString('id="'.$id.'"', $html, $path);
            }
        }
    }

    public function test_no_other_brand_no_outcome_guarantee_no_premature_availability(): void
    {
        $banned = ['Le Voile', 'FallOffer', 'levoile', 'EGP', 'guarantee', 'boost', 'skyrocket', 'double your', 'increase your sales', 'more sales', 'available on the', 'now on the shopify app store', 'نضمن', 'مضمون'];
        foreach (self::PAGES as $path) {
            $text = strtolower(strip_tags($this->page($path)));
            foreach ($banned as $word) {
                $this->assertStringNotContainsString(strtolower($word), $text, "$path: $word");
            }
        }
        foreach (['lang/en/site.php', 'lang/ar/site.php', 'public/site.css', 'public/site/site.js', 'public/site/scene.js'] as $file) {
            foreach (['Le Voile', 'FallOffer'] as $word) {
                $this->assertStringNotContainsString($word, (string) file_get_contents(base_path($file)), $file);
            }
        }
    }

    public function test_privacy_lists_what_is_read_and_how_it_is_deleted(): void
    {
        $html = $this->page('/privacy');
        foreach (['discounts', 'products', 'orders', 'inventory', 'customer', 'encrypted', 'shop/redact'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $html);
        }
        $this->assertStringContainsString('shop/redact', $this->page('/ar/privacy'));
    }

    public function test_legal_pages_show_a_visible_placeholder_until_the_owner_supplies_the_identity(): void
    {
        config(['shopify.legal_name' => null, 'shopify.support_email' => null]);
        foreach (['/privacy', '/terms', '/ar/privacy', '/ar/terms'] as $path) {
            $this->assertStringContainsString('class="placeholder"', $this->page($path), $path);
        }
    }

    public function test_legal_pages_use_the_configured_identity(): void
    {
        config(['shopify.legal_name' => 'Acme <Trading> LLC', 'shopify.support_email' => 'help@acme.test']);
        foreach (['/privacy', '/terms'] as $path) {
            $html = $this->page($path);
            $this->assertStringContainsString('Acme &lt;Trading&gt; LLC', $html, $path);
            $this->assertStringContainsString('mailto:help@acme.test', $html, $path);
            $this->assertStringNotContainsString('class="placeholder"', $html, $path);
        }
    }

    public function test_install_button_uses_the_config_link_with_a_neutral_label(): void
    {
        config(['shopify.install_url' => 'https://admin.shopify.com/oauth/install?client_id=abc']);
        $html = $this->page('/');
        $this->assertStringContainsString('href="https://admin.shopify.com/oauth/install?client_id=abc"', html_entity_decode($html));
        $this->assertStringContainsString('Get Pairly', $html);
    }

    public function test_install_button_without_a_link_goes_to_the_setup_docs(): void
    {
        config(['shopify.install_url' => null]);
        $this->assertStringContainsString('href="'.self::SITE.'/docs#setup"', $this->page('/'));
    }

    public function test_links_are_built_from_the_site_url_and_alternates_are_declared(): void
    {
        config(['shopify.site_url' => 'https://pairly.test/']);
        $html = $this->page('/pricing');
        $this->assertStringContainsString('<link rel="canonical" href="https://pairly.test/pricing">', $html);
        $this->assertStringContainsString('hreflang="ar" href="https://pairly.test/ar/pricing"', $html);
        $this->assertStringContainsString('href="https://pairly.test/docs"', $html);
    }

    public function test_the_page_requests_nothing_from_another_host(): void
    {
        config(['shopify.install_url' => null]);
        foreach (self::PAGES as $path) {
            $html = $this->page($path);
            preg_match_all('/<(?:script|img|iframe|source)\b[^>]*\bsrc="([^"]+)"/i', $html, $src);
            preg_match_all('/<link\b(?![^>]*rel="(?:canonical|alternate)")[^>]*\bhref="([^"]+)"/i', $html, $links);
            foreach ([...$src[1], ...$links[1]] as $url) {
                $this->assertStringStartsWith('/', $url, "$path: $url");
                $this->assertStringStartsNotWith('//', $url, "$path: $url");
                $this->assertFileExists(public_path(strtok($url, '?')), "$path: $url");
            }
        }
        foreach (['public/site.css', 'public/site/site.js', 'public/site/scene.js'] as $file) {
            $this->assertDoesNotMatchRegularExpression('#(url\(|import\(|from |fetch\()\s*[\'"]?https?://#', (string) file_get_contents(base_path($file)), $file);
        }
    }

    public function test_three_is_pinned_and_licensed_and_the_scene_is_lazy_with_a_fallback(): void
    {
        $dir = public_path('vendor/three-0.186.1');
        $this->assertFileExists($dir.'/three.module.min.js');
        $this->assertFileExists($dir.'/three.core.min.js');
        $this->assertFileExists($dir.'/LICENSE');

        $js = (string) file_get_contents(public_path('site/site.js'));
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertStringContainsString('webgl', $js);
        $this->assertStringContainsString('import(', $js);

        $html = $this->page('/');
        $this->assertStringContainsString('data-three="/vendor/three-0.186.1/three.module.min.js"', $html);
        // The fallback screens are real markup in the page, one per chapter.
        $this->assertSame(4, substr_count($html, 'class="screen"'));
        $this->assertStringNotContainsString('three.module.min.js"></script>', $html);
    }

    public function test_fonts_are_self_hosted_with_their_license(): void
    {
        $this->assertFileExists(public_path('vendor/fonts/alexandria-arabic.woff2'));
        $this->assertFileExists(public_path('vendor/fonts/alexandria-latin.woff2'));
        $this->assertFileExists(public_path('vendor/fonts/LICENSE'));
    }

    public function test_logo_favicon_and_store_icon_exist_at_the_right_size(): void
    {
        $this->assertStringContainsString('<svg', (string) file_get_contents(public_path('brand/logo.svg')));
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));

        $icon = base_path('../docs/app-store/assets/icon-1200.png');
        [$width, $height, $type] = getimagesize($icon);
        $this->assertSame([1200, 1200, IMAGETYPE_PNG], [$width, $height, $type]);
    }

    public function test_language_files_have_identical_keys_and_no_empty_values(): void
    {
        $flatten = function (array $a, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? $out += $flatten($v, "$prefix$k.") : $out["$prefix$k"] = $v;
            }

            return $out;
        };
        $en = $flatten(require base_path('lang/en/site.php'));
        $ar = $flatten(require base_path('lang/ar/site.php'));

        $this->assertSame(array_keys($en), array_keys($ar));
        foreach ($ar as $key => $value) {
            $this->assertNotSame('', trim((string) $value), $key);
        }
    }

    public function test_language_switch_links_to_the_same_page(): void
    {
        $this->assertStringContainsString('href="'.self::SITE.'/ar/terms"', $this->page('/terms'));
        $this->assertStringContainsString('href="'.self::SITE.'/terms"', $this->page('/ar/terms'));
    }
}
