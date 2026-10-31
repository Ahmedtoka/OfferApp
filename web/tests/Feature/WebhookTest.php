<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '{"shop_id":954889,"myshopify_domain":"levoilestores.myshopify.com","secret_field":"do-not-log"}';

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
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

    private function hook(string $topic, ?string $hmac = null, ?string $body = null)
    {
        $body ??= self::BODY;
        $hmac ??= base64_encode(hash_hmac('sha256', $body, SessionTokens::API_SECRET, true));

        return $this->call('POST', '/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => SessionTokens::SHOP,
        ], $body);
    }

    public function test_bad_hmac_returns_401_empty_object_and_changes_nothing(): void
    {
        $shop = $this->installShop();

        foreach (['bm90LXZhbGlk', '', base64_encode(hash_hmac('sha256', 'other', SessionTokens::API_SECRET, true))] as $bad) {
            $res = $this->hook('app/uninstalled', $bad);
            $res->assertStatus(401);
            $this->assertSame('{}', $res->getContent());
        }

        $shop->refresh();
        $this->assertNull($shop->uninstalled_at);
        $this->assertSame('shpat_existing', $shop->access_token);
    }

    public function test_missing_hmac_header_returns_401(): void
    {
        $res = $this->call('POST', '/webhooks', [], [], [], [
            'HTTP_X_SHOPIFY_TOPIC' => 'app/uninstalled',
        ], self::BODY);

        $res->assertStatus(401);
        $this->assertSame('{}', $res->getContent());
    }

    public function test_uninstall_marks_shop_uninstalled_and_nulls_token(): void
    {
        $shop = $this->installShop();

        $res = $this->hook('app/uninstalled');

        $res->assertOk();
        $this->assertSame('{}', $res->getContent());
        $shop->refresh();
        $this->assertNotNull($shop->uninstalled_at);
        $this->assertNull($shop->access_token);
        $this->assertNull(Shop::active(SessionTokens::SHOP));
    }

    public function test_uninstall_for_unknown_shop_is_ok(): void
    {
        $this->hook('app/uninstalled')->assertOk();
    }

    public function test_compliance_topics_return_200_and_log_topic_and_shop_only(): void
    {
        foreach (['customers/data_request', 'customers/redact'] as $topic) {
            Log::spy();

            $res = $this->hook($topic);

            $res->assertOk();
            $this->assertSame('{}', $res->getContent());
            Log::shouldHaveReceived('info')->atLeast()->once()->withArgs(
                fn ($message, $context = []) => str_contains($message, $topic)
                    && ($context['shop'] ?? null) === SessionTokens::SHOP
                    && ! str_contains(json_encode($context), 'do-not-log')
            );
        }
    }

    public function test_customers_topics_leave_the_shop_row_alone(): void
    {
        $this->installShop();

        $this->hook('customers/redact')->assertOk();
        $this->hook('customers/data_request')->assertOk();

        $this->assertNotNull(Shop::active(SessionTokens::SHOP));
    }

    public function test_shop_redact_deletes_the_shop_row_and_logs_without_body(): void
    {
        $this->installShop();
        Log::spy();

        $res = $this->hook('shop/redact', null, '{"shop_id":954889,"shop_domain":"levoilestores.myshopify.com","secret_field":"do-not-log"}');

        $res->assertOk();
        $this->assertSame('{}', $res->getContent());
        $this->assertSame(0, Shop::count());
        Log::shouldHaveReceived('info')->atLeast()->once()->withArgs(
            fn ($message, $context = []) => str_contains($message, 'shop/redact')
                && ! str_contains(json_encode($context), 'do-not-log')
        );
    }

    public function test_shop_redact_for_unknown_shop_is_ok(): void
    {
        $this->hook('shop/redact', null, '{"shop_id":954889,"shop_domain":"levoilestores.myshopify.com"}')->assertOk();
    }

    public function test_shop_redact_with_mismatched_body_domain_changes_nothing(): void
    {
        $this->installShop();

        $this->hook('shop/redact', null, '{"shop_id":954889,"shop_domain":"other-store.myshopify.com"}')->assertOk();

        $this->assertSame(1, Shop::count());
    }

    public function test_shop_redact_without_body_shop_domain_changes_nothing(): void
    {
        $this->installShop();

        $this->hook('shop/redact', null, '{"shop_id":954889}')->assertOk();

        $this->assertSame(1, Shop::count());
    }

    public function test_uninstall_with_mismatched_body_domain_changes_nothing(): void
    {
        $shop = $this->installShop();

        $this->hook('app/uninstalled', null, '{"myshopify_domain":"other-store.myshopify.com"}')->assertOk();

        $shop->refresh();
        $this->assertNull($shop->uninstalled_at);
        $this->assertSame('shpat_existing', $shop->access_token);
    }

    public function test_uninstall_with_a_null_body_domain_proceeds(): void
    {
        // Shopify's own sample payload for app/uninstalled carries "myshopify_domain": null.
        $shop = $this->installShop();

        $this->hook('app/uninstalled', null, '{"id":954889,"name":"x","myshopify_domain":null}')->assertOk();

        $shop->refresh();
        $this->assertNotNull($shop->uninstalled_at);
        $this->assertNull($shop->access_token);
    }

    public function test_a_rejected_uninstall_is_logged_with_topic_and_shop_only(): void
    {
        $this->installShop();
        foreach (['{"shop_id":1,"secret_field":"do-not-log"}', '{"myshopify_domain":"other-store.myshopify.com","secret_field":"do-not-log"}'] as $body) {
            Log::spy();

            $this->hook('app/uninstalled', null, $body)->assertOk();

            Log::shouldHaveReceived('warning')->atLeast()->once()->withArgs(
                fn ($message, $context = []) => str_contains($message, 'app/uninstalled')
                    && $context === ['shop' => SessionTokens::SHOP]
                    && ! str_contains($message.json_encode($context), 'do-not-log')
            );
        }
    }

    public function test_unknown_topic_returns_200(): void
    {
        $this->hook('orders/create')->assertOk();
    }
}
