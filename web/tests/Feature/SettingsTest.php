<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Shopify\GraphqlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeGraphqlClient;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Saved. The discount in Shopify is up to date.';

    private FakeGraphqlClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        config(['shopify.function_title' => 'Pairly Discount']);
        Shop::create([
            'shop_domain' => SessionTokens::SHOP,
            'access_token' => 'shpat_existing',
            'scopes' => 'write_discounts',
            'installed_at' => now(),
        ]);
        $this->fake = new FakeGraphqlClient;
        $this->app->instance(GraphqlClient::class, $this->fake);
    }

    private function auth(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint());
    }

    /** Bodies get eligibleTag "FallOffer" unless they set it or $withTag is false. */
    private function save(array $body, bool $withTag = true)
    {
        if ($withTag && ! array_key_exists('eligibleTag', $body)) {
            $body['eligibleTag'] = 'FallOffer';
        }

        return $this->auth()->putJson('/api/settings', $body);
    }

    private const DISCOUNT_CONFIG = '{"eligibleTags":["FallOffer"]}';

    public function test_save_success_writes_reads_back_and_ensures_discount(): void
    {
        $res = $this->save(['enabled' => true, 'percent' => 30]);

        $res->assertOk()
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 30)
            ->assertJsonPath('settings.eligibleTag', 'FallOffer')
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/888')
            ->assertJsonPath('discount.status', 'ACTIVE')
            ->assertJsonPath('discount.adminUrl', 'https://admin.shopify.com/store/levoilestores/discounts/888');
        $this->assertNotNull($res->json('settings.updated_at'));
        $this->assertSame(
            ['ShopId', 'WriteSettings', 'ReadSettings', 'AppFunctions', 'AppDiscounts', 'CreateDiscount', 'ReadDiscountConfig'],
            $this->fake->ops()
        );

        $write = $this->fake->calls[1]['variables']['metafields'][0];
        $this->assertSame('gid://shopify/Shop/90000000001', $write['ownerId']);
        $this->assertSame('$app:bogo', $write['namespace']);
        $this->assertSame('config', $write['key']);
        $this->assertSame('json', $write['type']);
        $this->assertSame('FallOffer', json_decode($write['value'], true)['eligibleTag']);

        $create = $this->fake->calls[5]['variables']['d'];
        $this->assertSame('01BOGOFUNCTION', $create['functionId']);
        $this->assertSame(['PRODUCT'], $create['discountClasses']);
        $this->assertSame(['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false], $create['combinesWith']);
        // The Function's input query variables: a JSON metafield on the discount itself.
        $this->assertSame(
            [['namespace' => '$app:bogo', 'key' => 'config', 'type' => 'json', 'value' => self::DISCOUNT_CONFIG]],
            $create['metafields']
        );

        $read = $this->fake->calls[6]['variables'];
        $this->assertSame(['id' => 'gid://shopify/DiscountAutomaticNode/888', 'namespace' => '$app:bogo', 'key' => 'config'], $read);
    }

    public function test_existing_discount_gets_the_tag_metafield_written_and_read_back(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';

        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'WinterOffer'])
            ->assertOk()
            ->assertJsonPath('settings.eligibleTag', 'WinterOffer')
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777');

        $this->assertSame(
            ['ShopId', 'WriteSettings', 'ReadSettings', 'AppFunctions', 'AppDiscounts', 'WriteDiscountConfig', 'ReadDiscountConfig'],
            $this->fake->ops()
        );
        $this->assertSame([[
            'ownerId' => 'gid://shopify/DiscountAutomaticNode/777',
            'namespace' => '$app:bogo',
            'key' => 'config',
            'type' => 'json',
            'value' => '{"eligibleTags":["WinterOffer"]}',
        ]], $this->fake->calls[5]['variables']['metafields']);
        $this->assertSame('gid://shopify/DiscountAutomaticNode/777', $this->fake->calls[6]['variables']['id']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_discount_tag_read_back_mismatch_on_existing_discount_returns_422(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->fake->discountReadBackOverride = '{"eligibleTags":["FallOffer"]}';

        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'WinterOffer'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'discount tag read-back mismatch']);
    }

    public function test_discount_tag_read_back_mismatch_on_created_discount_returns_422(): void
    {
        foreach (['{}', 'not json', '{"eligibleTags":"FallOffer"}', '{"eligibleTags":["FallOffer","X"]}', 'null'] as $value) {
            $this->fake = new FakeGraphqlClient;
            $this->fake->discountReadBackOverride = $value;
            $this->app->instance(GraphqlClient::class, $this->fake);

            $this->save(['enabled' => true, 'percent' => 30])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'discount tag read-back mismatch']);
            $this->assertSame('ReadDiscountConfig', $this->fake->ops()[count($this->fake->calls) - 1]);
        }
    }

    public function test_discount_metafield_missing_on_read_back_returns_422(): void
    {
        $fake = new class extends FakeGraphqlClient
        {
            public function query(string $shop, string $query, array $variables = []): array
            {
                $data = parent::query($shop, $query, $variables);

                return isset($data['discountNode']) ? ['discountNode' => ['metafield' => null]] : $data;
            }
        };
        $fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->app->instance(GraphqlClient::class, $fake);

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'discount tag read-back mismatch']);
    }

    public function test_discount_metafield_user_error_returns_422_with_that_text(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->fake->fixtures['WriteDiscountConfig'] = 'metafields-set-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Value is invalid JSON']);
        $this->assertSame(0, $this->fake->count('ReadDiscountConfig'));
    }

    public function test_read_back_tag_mismatch_returns_422_and_never_touches_discount(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'FallOffer']);

        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'WinterOffer'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'read-back mismatch']);
        $this->assertSame(['ShopId', 'WriteSettings', 'ReadSettings'], $this->fake->ops());
    }

    public function test_tag_is_trimmed_before_saving(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => '  WinterOffer  '])
            ->assertOk()
            ->assertJsonPath('settings.eligibleTag', 'WinterOffer');
        $this->assertSame('{"eligibleTags":["WinterOffer"]}', $this->fake->calls[5]['variables']['d']['metafields'][0]['value']);
    }

    public function test_tag_validation_failures(): void
    {
        foreach (['', '   ', 'Fall,Offer', ',', str_repeat('a', 101), 123, true, ['FallOffer'], null] as $tag) {
            $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => $tag])
                ->assertStatus(422)
                ->assertJsonValidationErrors('eligibleTag');
        }
        $this->save(['enabled' => true, 'percent' => 30], withTag: false)
            ->assertStatus(422)
            ->assertJsonValidationErrors('eligibleTag');
        $this->assertSame([], $this->fake->calls);
    }

    public function test_tag_boundaries_are_accepted(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'A'])->assertOk()->assertJsonPath('settings.eligibleTag', 'A');
        $long = str_repeat('a', 100);
        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => $long])->assertOk()->assertJsonPath('settings.eligibleTag', $long);
        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'Fall Offer 2026'])->assertOk();
    }

    public function test_comma_error_message_is_readable(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'a,b'])
            ->assertStatus(422)
            ->assertJsonPath('errors.eligibleTag.0', 'A tag cannot contain commas.');
    }

    public function test_second_save_does_not_create_a_duplicate_discount(): void
    {
        $this->save(['enabled' => true, 'percent' => 30])->assertOk();
        $res = $this->save(['enabled' => false, 'percent' => 25]);

        $res->assertOk()->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777');
        $this->assertSame(1, $this->fake->count('CreateDiscount'));
        $this->assertSame(0, $this->fake->count('UpdateDiscount'));
    }

    public function test_drifted_discount_title_is_renamed_not_duplicated(): void
    {
        $fake = new class extends FakeGraphqlClient
        {
            public function query(string $shop, string $query, array $variables = []): array
            {
                $data = parent::query($shop, $query, $variables);
                if (isset($data['discountNodes'])) {
                    $data['discountNodes']['nodes'][1]['discount']['title'] = 'Edited by merchant';
                }

                return $data;
            }
        };
        $fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->app->instance(GraphqlClient::class, $fake);

        $this->save(['enabled' => true, 'percent' => 30])->assertOk();

        $this->assertSame(0, $fake->count('CreateDiscount'));
        $this->assertSame(1, $fake->count('UpdateDiscount'));
        $this->assertSame('Buy One Get One', $fake->calls[array_search('UpdateDiscount', $fake->ops(), true)]['variables']['d']['title']);
    }

    public function test_expired_discount_is_reactivated_before_reporting_success(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertOk()
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777')
            ->assertJsonPath('discount.status', 'ACTIVE');
        $this->assertSame(
            ['ShopId', 'WriteSettings', 'ReadSettings', 'AppFunctions', 'AppDiscounts', 'ActivateDiscount', 'AppDiscounts', 'WriteDiscountConfig', 'ReadDiscountConfig'],
            $this->fake->ops()
        );
        $this->assertSame('gid://shopify/DiscountAutomaticNode/777', $this->fake->calls[5]['variables']['id']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_activation_user_error_returns_422_with_that_text(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';
        $this->fake->fixtures['ActivateDiscount'] = 'discount-activate-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Discount could not be activated']);
    }

    public function test_discount_still_inactive_after_activation_returns_422(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-scheduled';
        $this->fake->afterActivate = 'discount-nodes-scheduled';

        $res = $this->save(['enabled' => true, 'percent' => 30])->assertStatus(422);

        $this->assertStringContainsString('SCHEDULED', $res->json('error'));
        $this->assertSame(1, $this->fake->count('ActivateDiscount'));
    }

    public function test_metafield_user_error_returns_422_and_never_touches_discount(): void
    {
        $this->fake->fixtures['WriteSettings'] = 'metafields-set-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Value is invalid JSON']);
        $this->assertSame(['ShopId', 'WriteSettings'], $this->fake->ops());
    }

    public function test_read_back_mismatch_returns_422_and_never_touches_discount(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 25, 'eligibleTag' => 'FallOffer', 'updated_at' => 'x']);

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'read-back mismatch']);
        $this->assertSame(['ShopId', 'WriteSettings', 'ReadSettings'], $this->fake->ops());
    }

    public function test_read_back_enabled_mismatch_is_also_rejected(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => false, 'percent' => 30, 'eligibleTag' => 'FallOffer']);

        $this->save(['enabled' => true, 'percent' => 30])->assertStatus(422)->assertJsonPath('error', 'read-back mismatch');
    }

    public function test_discount_create_user_error_returns_422_with_that_text(): void
    {
        $this->fake->fixtures['CreateDiscount'] = 'discount-create-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Function is not available']);
    }

    public function test_missing_function_returns_422_instead_of_success(): void
    {
        config(['shopify.function_title' => 'Something else']);

        $this->save(['enabled' => true, 'percent' => 30])->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_validation_failures(): void
    {
        foreach ([
            ['enabled' => true, 'percent' => 0],
            ['enabled' => true, 'percent' => 100],
            ['enabled' => true, 'percent' => 'abc'],
            ['enabled' => true, 'percent' => 12.5],
            ['enabled' => true],
            ['percent' => 30],
            ['enabled' => 'maybe', 'percent' => 30],
        ] as $body) {
            $this->save($body)->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        }
        $this->assertSame([], $this->fake->calls);
    }

    /** Security review F1: Laravel's loose `integer` rule casts JSON `true` to 1 and would save 1%. */
    public function test_non_numeric_scalar_percents_are_rejected(): void
    {
        foreach ([true, '30.5', '', null] as $percent) {
            $this->save(['enabled' => true, 'percent' => $percent])
                ->assertStatus(422)
                ->assertJsonValidationErrors('percent');
        }
        $this->assertSame([], $this->fake->calls);
    }

    public function test_boundary_percents_are_accepted(): void
    {
        $this->save(['enabled' => false, 'percent' => 1])->assertOk()->assertJsonPath('settings.enabled', false);
        $this->save(['enabled' => true, 'percent' => 99])->assertOk()->assertJsonPath('settings.percent', 99);
    }

    public function test_get_returns_defaults_and_null_discount_without_creating(): void
    {
        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertExactJson(['settings' => ['enabled' => false, 'percent' => 30, 'eligibleTag' => 'FallOffer', 'updated_at' => null], 'discount' => null]);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_config_saved_before_the_tag_was_editable_reads_the_default_tag(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 35]);

        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 35)
            ->assertJsonPath('settings.eligibleTag', 'FallOffer');
    }

    public function test_get_returns_saved_settings_and_existing_discount(): void
    {
        $this->save(['enabled' => true, 'percent' => 40, 'eligibleTag' => 'WinterOffer'])->assertOk();

        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 40)
            ->assertJsonPath('settings.eligibleTag', 'WinterOffer')
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777')
            ->assertJsonPath('discount.adminUrl', 'https://admin.shopify.com/store/levoilestores/discounts/777');
        $this->assertSame(1, $this->fake->count('CreateDiscount'));
    }

    public function test_requires_session_token(): void
    {
        $this->getJson('/api/settings')->assertStatus(401);
        $this->putJson('/api/settings', ['enabled' => true, 'percent' => 30])->assertStatus(401);
    }
}
