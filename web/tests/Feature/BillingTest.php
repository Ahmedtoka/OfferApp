<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Billing;
use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeGraphqlClient;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeGraphqlClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        config(['shopify.function_title' => 'Pairly Discount', 'shopify.billing_required' => true, 'shopify.app_handle' => 'pairly']);
        Shop::create([
            'shop_domain' => SessionTokens::SHOP,
            'access_token' => 'shpat_existing',
            'scopes' => 'write_discounts',
            'installed_at' => now(),
        ]);
        $this->fake = new FakeGraphqlClient;
        $this->app->instance(GraphqlClient::class, $this->fake);
        Carbon::setTestNow('2026-10-31 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint());
    }

    private function turnOn()
    {
        return $this->auth()->putJson('/api/settings', ['enabled' => true, 'percent' => 30, 'eligibleTag' => 'FallOffer']);
    }

    private function hook(string $status, ?string $name = 'Pairly')
    {
        $body = json_encode(['app_subscription' => ['admin_graphql_api_id' => 'gid://shopify/AppSubscription/1', 'name' => $name, 'status' => $status]]);
        $hmac = base64_encode(hash_hmac('sha256', $body, SessionTokens::API_SECRET, true));

        return $this->call('POST', '/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => 'app_subscriptions/update',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => SessionTokens::SHOP,
        ], $body);
    }

    private function seedSettings(array $extra = []): void
    {
        $this->fake->metafieldValue = json_encode($extra + [
            'enabled' => true, 'percent' => 40, 'method' => 'company', 'appliesTo' => 'collection',
            'eligibleTag' => '', 'collectionId' => 'gid://shopify/Collection/123', 'combineWithCoupons' => true,
            'updated_at' => '2026-10-01T00:00:00+00:00',
        ]);
    }

    private function subscribeVariables(): array
    {
        return $this->fake->calls[array_search('CreateSubscription', $this->fake->ops(), true)]['variables'];
    }

    public function test_constants(): void
    {
        $this->assertSame('Pairly', Billing::PLAN_NAME);
        $this->assertSame(19.99, Billing::PRICE_USD);
        $this->assertSame(14, Billing::TRIAL_DAYS);
    }

    public function test_subscribe_sends_the_plan_and_returns_the_confirmation_url(): void
    {
        config(['app.env' => 'production']);
        $this->fake->fixtures['BillingStatus'] = 'billing-none';

        $res = $this->auth()->postJson('/api/billing/subscribe');

        $res->assertOk()->assertJsonPath('confirmationUrl', fn ($u) => str_contains($u, '/charges/1/confirm'));
        $vars = $this->subscribeVariables();
        $this->assertSame('Pairly', $vars['name']);
        $this->assertSame(14, $vars['trialDays']);
        $this->assertFalse($vars['test']);
        $this->assertSame('https://admin.shopify.com/store/levoilestores/apps/pairly', $vars['returnUrl']);
        $pricing = $vars['lineItems'][0]['plan']['appRecurringPricingDetails'];
        $this->assertSame(['amount' => 19.99, 'currencyCode' => 'USD'], $pricing['price']);
        $this->assertSame('EVERY_30_DAYS', $pricing['interval']);
    }

    public function test_subscribe_is_a_test_charge_outside_production(): void
    {
        config(['app.env' => 'local']);
        $this->fake->fixtures['BillingStatus'] = 'billing-none';

        $this->auth()->postJson('/api/billing/subscribe')->assertOk();

        $this->assertTrue($this->subscribeVariables()['test']);
    }

    public function test_subscribe_is_a_test_charge_for_a_partner_dev_store_even_in_production(): void
    {
        config(['app.env' => 'production']);
        $this->fake->fixtures['BillingStatus'] = 'billing-dev';

        $this->auth()->postJson('/api/billing/subscribe')->assertOk();

        $this->assertTrue($this->subscribeVariables()['test']);
    }

    public function test_subscribe_user_error_returns_422_with_its_text(): void
    {
        $this->fake->fixtures['CreateSubscription'] = 'subscription-create-error';

        $this->auth()->postJson('/api/billing/subscribe')
            ->assertStatus(422)
            ->assertJsonPath('error', 'Apps without a public distribution cannot use the Billing API.');
    }

    public function test_billing_endpoints_need_a_session_token(): void
    {
        $this->getJson('/api/billing')->assertStatus(401);
        $this->postJson('/api/billing/subscribe')->assertStatus(401);
    }

    public function test_status_with_an_active_subscription_reports_trial_days_left(): void
    {
        // Created 2026-10-25 with 14 trial days; now 2026-10-31: 8 left.
        $this->auth()->getJson('/api/billing')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('trialDaysLeft', 8)
            ->assertJsonPath('partnerDevelopment', false);
    }

    public function test_trial_days_left_never_goes_negative(): void
    {
        Carbon::setTestNow('2026-12-31 10:00:00');

        $this->auth()->getJson('/api/billing')->assertOk()->assertJsonPath('active', true)->assertJsonPath('trialDaysLeft', 0);
    }

    public function test_status_without_subscription_is_inactive(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-none';

        $this->auth()->getJson('/api/billing')
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('trialDaysLeft', null)
            ->assertJsonPath('partnerDevelopment', false);
    }

    public function test_dev_store_is_active_without_a_subscription(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-dev';

        $this->auth()->getJson('/api/billing')
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('partnerDevelopment', true);
    }

    public function test_turning_on_without_a_subscription_returns_402_and_saves_nothing(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-none';

        $this->turnOn()
            ->assertStatus(402)
            ->assertJsonPath('error', fn ($m) => str_contains($m, 'Subscribe') && str_contains($m, 'Pairly'));
        $this->assertSame(['BillingStatus'], $this->fake->ops());
    }

    public function test_turning_on_with_a_subscription_saves(): void
    {
        $this->turnOn()->assertOk()->assertJsonPath('settings.enabled', true);
    }

    public function test_dev_store_can_turn_on_without_a_subscription(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-dev';

        $this->turnOn()->assertOk();
    }

    public function test_turning_off_is_always_allowed_and_skips_the_billing_check(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-none';

        $this->auth()->putJson('/api/settings', ['enabled' => false, 'percent' => 30, 'eligibleTag' => 'FallOffer'])->assertOk();

        $this->assertSame(0, $this->fake->count('BillingStatus'));
    }

    public function test_older_deployments_that_do_not_bill_skip_the_gate(): void
    {
        config(['shopify.billing_required' => false]);
        $this->fake->fixtures['BillingStatus'] = 'billing-none';

        $this->turnOn()->assertOk();

        $this->assertSame(0, $this->fake->count('BillingStatus'));
    }

    public function test_billing_endpoint_reports_active_when_not_required(): void
    {
        config(['shopify.billing_required' => false]);

        $this->auth()->getJson('/api/billing')->assertOk()->assertJsonPath('active', true)->assertJsonPath('required', false);
        $this->assertSame([], $this->fake->ops());
    }

    public function test_cancelled_expired_and_frozen_turn_the_offer_off_and_keep_the_other_fields(): void
    {
        foreach (['CANCELLED', 'EXPIRED', 'FROZEN'] as $status) {
            $this->fake = new FakeGraphqlClient;
            $this->app->instance(GraphqlClient::class, $this->fake);
            $this->fake->fixtures['BillingStatus'] = 'billing-none';
            $this->seedSettings();

            $this->hook($status)->assertOk();

            $written = json_decode($this->fake->metafieldValue, true);
            $this->assertFalse($written['enabled'], $status);
            $this->assertSame('billing', $written['disabled_reason'], $status);
            $this->assertSame(40, $written['percent']);
            $this->assertSame('company', $written['method']);
            $this->assertSame('collection', $written['appliesTo']);
            $this->assertSame('gid://shopify/Collection/123', $written['collectionId']);
            $this->assertTrue($written['combineWithCoupons']);
        }
    }

    public function test_settings_report_that_billing_ended_and_a_later_enabled_save_clears_it(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-none';
        $this->seedSettings();
        $this->hook('CANCELLED')->assertOk();

        $this->auth()->getJson('/api/settings')->assertOk()
            ->assertJsonPath('settings.enabled', false)
            ->assertJsonPath('settings.disabledReason', 'billing');

        $this->fake->fixtures['BillingStatus'] = 'billing-active';
        $this->turnOn()->assertOk()->assertJsonPath('settings.disabledReason', null);
        $this->assertArrayNotHasKey('disabled_reason', json_decode($this->fake->metafieldValue, true));
    }

    public function test_other_statuses_do_nothing(): void
    {
        $this->seedSettings();

        foreach (['ACTIVE', 'PENDING', 'DECLINED'] as $status) {
            $this->hook($status)->assertOk();
        }

        $this->assertSame([], $this->fake->ops());
    }

    public function test_a_cancelled_old_subscription_is_ignored_while_another_is_active(): void
    {
        $this->seedSettings();

        $this->hook('CANCELLED')->assertOk();

        $this->assertSame(['BillingStatus'], $this->fake->ops());
        $this->assertTrue(json_decode($this->fake->metafieldValue, true)['enabled']);
    }

    public function test_webhook_for_a_shop_that_is_not_active_is_a_no_op(): void
    {
        Shop::where('shop_domain', SessionTokens::SHOP)->update(['access_token' => null, 'uninstalled_at' => now()]);

        $this->hook('CANCELLED')->assertOk();

        $this->assertSame([], $this->fake->ops());
    }

    public function test_webhook_does_nothing_when_the_deployment_does_not_bill(): void
    {
        config(['shopify.billing_required' => false]);

        $this->hook('CANCELLED')->assertOk();

        $this->assertSame([], $this->fake->ops());
    }

    public function test_webhook_for_an_offer_that_is_already_off_writes_nothing(): void
    {
        $this->fake->fixtures['BillingStatus'] = 'billing-none';
        $this->seedSettings(['enabled' => false]);
        $before = $this->fake->metafieldValue;

        $this->hook('CANCELLED')->assertOk();

        $this->assertSame($before, $this->fake->metafieldValue);
        $this->assertSame(0, $this->fake->count('WriteSettings'));
    }

    public function test_webhook_fails_with_500_when_shopify_is_down_so_shopify_retries(): void
    {
        $this->app->instance(GraphqlClient::class, new class implements GraphqlClient
        {
            public function query(string $shop, string $query, array $variables = []): array
            {
                throw new ShopifyGraphqlException('down');
            }
        });

        $this->hook('CANCELLED')->assertStatus(500);
    }
}
