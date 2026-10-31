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
        config(['shopify.function_title' => 'Pairly Discount', 'shopify.billing_required' => false]); // the gate is covered in BillingTest
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

    private const DISCOUNT_CONFIG = '{"eligibleTags":["FallOffer"],"collectionIds":[]}';

    private const COLLECTION = 'gid://shopify/Collection/123';

    /** Discount first, shop metafield last. */
    private const SAVE_OPS_NEW_DISCOUNT = ['AppFunctions', 'AppDiscounts', 'CreateDiscount', 'ReadDiscountConfig'];

    private const SAVE_OPS_NEW = ['AppFunctions', 'AppDiscounts', 'CreateDiscount', 'ReadDiscountConfig', 'ShopId', 'WriteSettings', 'ReadSettings'];

    private function shopWrite(): array
    {
        return json_decode($this->fake->calls[array_search('WriteSettings', $this->fake->ops(), true)]['variables']['metafields'][0]['value'], true);
    }

    public function test_save_success_creates_discount_first_then_writes_shop_settings_and_reads_back(): void
    {
        $res = $this->save(['enabled' => true, 'percent' => 30]);

        $res->assertOk()
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 30)
            ->assertJsonPath('settings.method', 'customer')
            ->assertJsonPath('settings.appliesTo', 'tag')
            ->assertJsonPath('settings.eligibleTag', 'FallOffer')
            ->assertJsonPath('settings.combineWithCoupons', false)
            ->assertJsonPath('state', 'running')
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/888')
            ->assertJsonPath('discount.status', 'ACTIVE')
            ->assertJsonPath('discount.adminUrl', 'https://admin.shopify.com/store/levoilestores/discounts/888');
        $this->assertNotNull($res->json('settings.updated_at'));
        $this->assertSame(self::SAVE_OPS_NEW, $this->fake->ops());

        $write = $this->fake->calls[5]['variables']['metafields'][0];
        $this->assertSame('gid://shopify/Shop/90000000001', $write['ownerId']);
        $this->assertSame('$app:bogo', $write['namespace']);
        $this->assertSame('config', $write['key']);
        $this->assertSame('json', $write['type']);
        $value = json_decode($write['value'], true);
        $this->assertSame('customer', $value['method']);
        $this->assertSame(30, $value['percent']);
        $this->assertTrue($value['enabled']);

        $create = $this->fake->calls[2]['variables']['d'];
        $this->assertSame('01BOGOFUNCTION', $create['functionId']);
        $this->assertSame(['PRODUCT'], $create['discountClasses']);
        $this->assertSame(['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false], $create['combinesWith']);
        $this->assertNotEmpty($create['startsAt']);
        // The Function's input query variables: a JSON metafield on the discount itself.
        $this->assertSame(
            [['namespace' => '$app:bogo', 'key' => 'config', 'type' => 'json', 'value' => self::DISCOUNT_CONFIG]],
            $create['metafields']
        );

        $read = $this->fake->calls[3]['variables'];
        $this->assertSame(['id' => 'gid://shopify/DiscountAutomaticNode/888', 'namespace' => '$app:bogo', 'key' => 'config'], $read);
    }

    public function test_existing_discount_is_updated_and_gets_the_config_metafield_written_and_read_back(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';

        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'WinterOffer'])
            ->assertOk()
            ->assertJsonPath('settings.eligibleTag', 'WinterOffer')
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777');

        $this->assertSame(
            ['AppFunctions', 'AppDiscounts', 'ReadDiscountConfig', 'UpdateDiscount', 'WriteDiscountConfig', 'ReadDiscountConfig', 'ShopId', 'WriteSettings', 'ReadSettings'],
            $this->fake->ops()
        );
        $this->assertSame([[
            'ownerId' => 'gid://shopify/DiscountAutomaticNode/777',
            'namespace' => '$app:bogo',
            'key' => 'config',
            'type' => 'json',
            'value' => '{"eligibleTags":["WinterOffer"],"collectionIds":[]}',
        ]], $this->fake->calls[4]['variables']['metafields']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_discount_config_read_back_mismatch_returns_422_and_shop_settings_stay_old(): void
    {
        $old = json_encode(['enabled' => true, 'percent' => 10]);
        $this->fake->metafieldValue = $old;
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->fake->discountReadBackOverride = '{"eligibleTags":["FallOffer"],"collectionIds":[]}';

        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'WinterOffer'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'discount config read-back mismatch']);
        $this->assertSame(0, $this->fake->count('WriteSettings'));
        $this->assertSame($old, $this->fake->metafieldValue);
    }

    public function test_discount_config_read_back_mismatch_on_created_discount_returns_422(): void
    {
        foreach (['{}', 'not json', '{"eligibleTags":"FallOffer"}', '{"eligibleTags":["FallOffer","X"],"collectionIds":[]}', '{"eligibleTags":["FallOffer"]}', 'null'] as $value) {
            $this->fake = new FakeGraphqlClient;
            $this->fake->discountReadBackOverride = $value;
            $this->app->instance(GraphqlClient::class, $this->fake);

            $this->save(['enabled' => true, 'percent' => 30])
                ->assertStatus(422)
                ->assertExactJson(['error' => 'discount config read-back mismatch']);
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
            ->assertExactJson(['error' => 'discount config read-back mismatch']);
        $this->assertSame(0, $fake->count('WriteSettings'));
    }

    public function test_discount_metafield_user_error_returns_422_with_that_text(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->fake->fixtures['WriteDiscountConfig'] = 'metafields-set-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Value is invalid JSON']);
        // Only the read of the previous rule; nothing is read back after the failed write.
        $this->assertSame(1, $this->fake->count('ReadDiscountConfig'));
        $this->assertSame(0, $this->fake->count('WriteSettings'));
    }

    public function test_discount_step_failure_leaves_the_shop_metafield_untouched(): void
    {
        $old = json_encode(['enabled' => true, 'percent' => 10, 'method' => 'customer']);
        $this->fake->metafieldValue = $old;
        $this->fake->fixtures['CreateDiscount'] = 'discount-create-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Function is not available']);
        $this->assertSame(0, $this->fake->count('WriteSettings'));
        $this->assertSame($old, $this->fake->metafieldValue);
    }

    public function test_shop_write_failure_after_the_discount_step_returns_422_not_success(): void
    {
        $this->fake->fixtures['WriteSettings'] = 'metafields-set-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Value is invalid JSON']);
        $this->assertSame([...self::SAVE_OPS_NEW_DISCOUNT, 'ShopId', 'WriteSettings', 'WriteDiscountConfig', 'ReadDiscountConfig'], $this->fake->ops());
    }

    public function test_shop_read_back_mismatch_returns_422(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 25, 'method' => 'customer', 'appliesTo' => 'tag', 'eligibleTag' => 'FallOffer']);

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'read-back mismatch']);
        $this->assertSame([...self::SAVE_OPS_NEW, 'WriteDiscountConfig', 'ReadDiscountConfig'], $this->fake->ops());
    }

    public function test_shop_read_back_method_mismatch_is_rejected(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 30, 'method' => 'customer', 'appliesTo' => 'tag', 'eligibleTag' => 'FallOffer']);

        $this->save(['enabled' => true, 'percent' => 30, 'method' => 'company'])
            ->assertStatus(422)->assertJsonPath('error', 'read-back mismatch');
    }

    public function test_tag_is_trimmed_before_saving(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => '  WinterOffer  '])
            ->assertOk()
            ->assertJsonPath('settings.eligibleTag', 'WinterOffer');
        $this->assertSame('{"eligibleTags":["WinterOffer"],"collectionIds":[]}', $this->fake->calls[2]['variables']['d']['metafields'][0]['value']);
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
        $this->save(['enabled' => true, 'percent' => 30, 'appliesTo' => 'tag'], withTag: false)
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

    public function test_collection_applies_to_requires_a_collection_gid(): void
    {
        foreach ([null, '', 'FallOffer', 'gid://shopify/Product/1', 'gid://shopify/Collection/', 'gid://shopify/Collection/abc', 5] as $id) {
            $this->save(['enabled' => true, 'percent' => 30, 'appliesTo' => 'collection', 'collectionId' => $id], withTag: false)
                ->assertStatus(422)
                ->assertJsonValidationErrors('collectionId');
        }
        $this->assertSame([], $this->fake->calls);
    }

    public function test_collection_does_not_need_a_tag(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'appliesTo' => 'collection', 'collectionId' => self::COLLECTION], withTag: false)->assertOk();
    }

    public function test_applies_to_method_and_dates_validation(): void
    {
        foreach ([
            ['appliesTo' => 'vendor'],
            ['method' => 'cheapest'],
            ['combineWithCoupons' => 'maybe'],
            ['startsAt' => 'not a date'],
            ['startsAt' => '2026-11-10T00:00:00Z', 'endsAt' => '2026-11-10T00:00:00Z'],
            ['startsAt' => '2026-11-10T00:00:00Z', 'endsAt' => '2026-11-01T00:00:00Z'],
        ] as $extra) {
            $this->save(['enabled' => true, 'percent' => 30] + $extra)->assertStatus(422)->assertJsonStructure(['errors']);
        }
        $this->assertSame([], $this->fake->calls);
    }

    public function test_switching_tag_to_collection_writes_collection_ids_and_reads_back(): void
    {
        $this->save(['enabled' => true, 'percent' => 30])->assertOk();
        $this->assertSame('{"eligibleTags":["FallOffer"],"collectionIds":[]}', $this->fake->discountMetafieldValue);

        $this->save(['enabled' => true, 'percent' => 30, 'appliesTo' => 'collection', 'collectionId' => self::COLLECTION], withTag: false)
            ->assertOk()
            ->assertJsonPath('settings.appliesTo', 'collection')
            ->assertJsonPath('settings.collectionId', self::COLLECTION);

        $this->assertSame('{"eligibleTags":[],"collectionIds":["'.self::COLLECTION.'"]}', $this->fake->discountMetafieldValue);

        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.appliesTo', 'collection')
            ->assertJsonPath('settings.collectionId', self::COLLECTION);
    }

    public function test_method_is_written_to_the_shop_metafield(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'method' => 'company'])->assertOk()->assertJsonPath('settings.method', 'company');
        $this->assertSame('company', $this->shopWrite()['method']);
    }

    public function test_combine_with_coupons_maps_to_combines_with(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'combineWithCoupons' => true])->assertOk()->assertJsonPath('settings.combineWithCoupons', true);
        $this->assertSame(
            ['orderDiscounts' => true, 'productDiscounts' => true, 'shippingDiscounts' => true],
            $this->fake->calls[2]['variables']['d']['combinesWith']
        );

        $this->fake = new FakeGraphqlClient;
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->app->instance(GraphqlClient::class, $this->fake);
        $this->save(['enabled' => true, 'percent' => 30, 'combineWithCoupons' => false])->assertOk();
        $update = $this->fake->calls[array_search('UpdateDiscount', $this->fake->ops(), true)]['variables']['d'];
        $this->assertSame(['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false], $update['combinesWith']);
    }

    public function test_dates_map_to_starts_at_and_ends_at(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'startsAt' => '2026-11-10T08:00:00+02:00', 'endsAt' => '2026-12-01T00:00:00Z'])->assertOk();
        $create = $this->fake->calls[2]['variables']['d'];
        $this->assertSame('2026-11-10T06:00:00Z', $create['startsAt']);
        $this->assertSame('2026-12-01T00:00:00Z', $create['endsAt']);

        $this->fake = new FakeGraphqlClient;
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->app->instance(GraphqlClient::class, $this->fake);
        $this->save(['enabled' => true, 'percent' => 30, 'endsAt' => null])->assertOk();
        $update = $this->fake->calls[array_search('UpdateDiscount', $this->fake->ops(), true)]['variables']['d'];
        $this->assertArrayNotHasKey('startsAt', $update);
        $this->assertArrayHasKey('endsAt', $update);
        $this->assertNull($update['endsAt']);
    }

    private function updatePayload(): array
    {
        return $this->fake->calls[array_search('UpdateDiscount', $this->fake->ops(), true)]['variables']['d'];
    }

    public function test_offer_off_save_never_sends_a_null_end_date(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';

        $this->save(['enabled' => false, 'percent' => 30])->assertOk();
        $this->assertArrayNotHasKey('endsAt', $this->updatePayload());
        $this->assertArrayNotHasKey('startsAt', $this->updatePayload());

        $this->fake = new FakeGraphqlClient;
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';
        $this->app->instance(GraphqlClient::class, $this->fake);
        $this->save(['enabled' => false, 'percent' => 30, 'endsAt' => null])->assertOk();
        $this->assertArrayNotHasKey('endsAt', $this->updatePayload());
        $this->assertSame(0, $this->fake->count('ActivateDiscount'));
    }

    public function test_omitted_dates_are_not_sent_when_the_offer_is_on(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';

        $this->save(['enabled' => true, 'percent' => 30])->assertOk();
        $this->assertArrayNotHasKey('endsAt', $this->updatePayload());
        $this->assertArrayNotHasKey('startsAt', $this->updatePayload());
    }

    public function test_explicit_null_end_date_clears_it_when_the_offer_is_on(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';

        $this->save(['enabled' => true, 'percent' => 30, 'endsAt' => null])->assertOk();
        $this->assertArrayHasKey('endsAt', $this->updatePayload());
        $this->assertNull($this->updatePayload()['endsAt']);
    }

    public function test_offer_off_save_still_sends_a_real_end_date(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';

        $this->save(['enabled' => false, 'percent' => 30, 'endsAt' => '2026-12-01T00:00:00Z'])->assertOk();
        $this->assertSame('2026-12-01T00:00:00Z', $this->updatePayload()['endsAt']);
    }

    public function test_final_write_failure_restores_the_previous_discount_rule(): void
    {
        $old = '{"eligibleTags":["OldTag"],"collectionIds":[]}';
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $this->fake->discountMetafieldValue = $old;
        $this->fake->fixtures['WriteSettings'] = 'metafields-set-error';

        $this->save(['enabled' => true, 'percent' => 30, 'eligibleTag' => 'WinterOffer'])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Value is invalid JSON']);
        $this->assertSame($old, $this->fake->discountMetafieldValue);
        $this->assertSame(2, $this->fake->count('WriteDiscountConfig'));
    }

    public function test_final_read_back_failure_restores_an_empty_rule_for_a_new_discount(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => false, 'percent' => 1]);

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'read-back mismatch']);
        $this->assertSame('{"eligibleTags":[],"collectionIds":[]}', $this->fake->discountMetafieldValue);
    }

    public function test_restore_failure_says_the_rule_may_be_out_of_step(): void
    {
        $fake = new class extends FakeGraphqlClient
        {
            private int $configWrites = 0;

            public function query(string $shop, string $query, array $variables = []): array
            {
                if (str_contains($query, 'mutation WriteDiscountConfig') && ++$this->configWrites === 2) {
                    $this->fixtures['WriteDiscountConfig'] = 'metafields-set-error';
                }

                return parent::query($shop, $query, $variables);
            }
        };
        $fake->fixtures['AppDiscounts'] = 'discount-nodes-existing';
        $fake->fixtures['WriteSettings'] = 'metafields-set-error';
        $this->app->instance(GraphqlClient::class, $fake);

        $res = $this->save(['enabled' => true, 'percent' => 30])->assertStatus(422);
        $this->assertStringContainsString('Value is invalid JSON', $res->json('error'));
        $this->assertStringContainsString('out of step', $res->json('error'));
        $this->assertStringContainsString('Save again', $res->json('error'));
    }

    public function test_switching_collection_to_tag_writes_tags_and_clears_collection_ids(): void
    {
        $this->save(['enabled' => true, 'percent' => 30, 'appliesTo' => 'collection', 'collectionId' => self::COLLECTION], withTag: false)->assertOk();
        $this->assertSame('{"eligibleTags":[],"collectionIds":["'.self::COLLECTION.'"]}', $this->fake->discountMetafieldValue);

        $this->save(['enabled' => true, 'percent' => 30, 'appliesTo' => 'tag', 'eligibleTag' => 'WinterOffer'])
            ->assertOk()
            ->assertJsonPath('settings.appliesTo', 'tag')
            ->assertJsonPath('settings.eligibleTag', 'WinterOffer')
            ->assertJsonPath('settings.collectionId', null);
        $this->assertSame('{"eligibleTags":["WinterOffer"],"collectionIds":[]}', $this->fake->discountMetafieldValue);
    }

    public function test_second_save_does_not_create_a_duplicate_discount(): void
    {
        $this->save(['enabled' => true, 'percent' => 30])->assertOk();
        $res = $this->save(['enabled' => false, 'percent' => 25]);

        $res->assertOk()->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777');
        $this->assertSame(1, $this->fake->count('CreateDiscount'));
        $this->assertSame(1, $this->fake->count('UpdateDiscount'));
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
        $this->assertSame('Pairly: BOGO Offers', $fake->calls[array_search('UpdateDiscount', $fake->ops(), true)]['variables']['d']['title']);
    }

    public function test_offer_on_reactivates_an_expired_discount_before_reporting_success(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertOk()
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/777')
            ->assertJsonPath('discount.status', 'ACTIVE');
        $this->assertSame(
            ['AppFunctions', 'AppDiscounts', 'ReadDiscountConfig', 'ActivateDiscount', 'AppDiscounts', 'UpdateDiscount', 'WriteDiscountConfig', 'ReadDiscountConfig', 'ShopId', 'WriteSettings', 'ReadSettings'],
            $this->fake->ops()
        );
        $this->assertSame('gid://shopify/DiscountAutomaticNode/777', $this->fake->calls[3]['variables']['id']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_offer_off_does_not_reactivate_a_deactivated_discount(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';

        $this->save(['enabled' => false, 'percent' => 30])->assertOk()->assertJsonPath('state', 'off');
        $this->assertSame(0, $this->fake->count('ActivateDiscount'));
        $this->assertFalse($this->shopWrite()['enabled']);
    }

    public function test_offer_off_with_no_discount_still_creates_one(): void
    {
        $this->save(['enabled' => false, 'percent' => 30])->assertOk()->assertJsonPath('state', 'off');
        $this->assertSame(1, $this->fake->count('CreateDiscount'));
        $this->assertSame(0, $this->fake->count('ActivateDiscount'));
    }

    public function test_offer_on_with_an_ended_end_date_does_not_activate(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';

        $this->save(['enabled' => true, 'percent' => 30, 'startsAt' => '2020-01-01T00:00:00Z', 'endsAt' => '2020-02-01T00:00:00Z'])->assertOk();
        $this->assertSame(0, $this->fake->count('ActivateDiscount'));
    }

    public function test_activation_user_error_returns_422_with_that_text(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';
        $this->fake->fixtures['ActivateDiscount'] = 'discount-activate-error';

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'Discount could not be activated']);
        $this->assertSame(0, $this->fake->count('WriteSettings'));
    }

    public function test_discount_still_inactive_after_activation_returns_422(): void
    {
        $this->fake->fixtures['AppDiscounts'] = 'discount-nodes-expired';
        $this->fake->afterActivate = 'discount-nodes-expired';

        $res = $this->save(['enabled' => true, 'percent' => 30])->assertStatus(422);

        $this->assertStringContainsString('EXPIRED', $res->json('error'));
        $this->assertSame(1, $this->fake->count('ActivateDiscount'));
        $this->assertSame(0, $this->fake->count('WriteSettings'));
    }

    public function test_missing_function_returns_422_instead_of_success(): void
    {
        config(['shopify.function_title' => 'Something else']);

        $this->save(['enabled' => true, 'percent' => 30])->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
        $this->assertSame(0, $this->fake->count('WriteSettings'));
    }

    public function test_offer_state_off_scheduled_running_ended(): void
    {
        $now = \Carbon\CarbonImmutable::parse('2026-11-10T12:00:00Z');
        $on = ['enabled' => true];
        $d = fn (?string $s, ?string $e, string $status = 'ACTIVE') => ['status' => $status, 'startsAt' => $s, 'endsAt' => $e];

        $this->assertSame('off', \App\Services\OfferState::from(['enabled' => false], $d('2026-11-01T00:00:00Z', null), $now));
        $this->assertSame('off', \App\Services\OfferState::from($on, null, $now));
        $this->assertSame('scheduled', \App\Services\OfferState::from($on, $d('2026-11-20T00:00:00Z', null, 'SCHEDULED'), $now));
        $this->assertSame('running', \App\Services\OfferState::from($on, $d('2026-11-01T00:00:00Z', null), $now));
        $this->assertSame('running', \App\Services\OfferState::from($on, $d('2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z'), $now));
        $this->assertSame('ended', \App\Services\OfferState::from($on, $d('2026-10-01T00:00:00Z', '2026-11-05T00:00:00Z', 'EXPIRED'), $now));
        // No dates reported: fall back to Shopify's status.
        $this->assertSame('ended', \App\Services\OfferState::from($on, $d(null, null, 'EXPIRED'), $now));
        $this->assertSame('scheduled', \App\Services\OfferState::from($on, $d(null, null, 'SCHEDULED'), $now));
        $this->assertSame('running', \App\Services\OfferState::from($on, $d(null, null), $now));
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
            ->assertExactJson(['settings' => [
                'enabled' => false, 'percent' => 30, 'method' => 'customer', 'appliesTo' => 'tag', 'eligibleTag' => '',
                'collectionId' => null, 'combineWithCoupons' => false, 'updated_at' => null, 'disabledReason' => null, 'startsAt' => null, 'endsAt' => null,
            ], 'discount' => null, 'state' => 'off']);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_older_config_reads_back_with_new_field_defaults(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 35]);

        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 35)
            ->assertJsonPath('settings.method', 'customer')
            ->assertJsonPath('settings.appliesTo', 'tag')
            ->assertJsonPath('settings.eligibleTag', '')
            ->assertJsonPath('settings.combineWithCoupons', false);
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
            ->assertJsonPath('discount.adminUrl', 'https://admin.shopify.com/store/levoilestores/discounts/777')
            ->assertJsonPath('state', 'running');
        $this->assertSame(1, $this->fake->count('CreateDiscount'));
    }

    public function test_requires_session_token(): void
    {
        $this->getJson('/api/settings')->assertStatus(401);
        $this->putJson('/api/settings', ['enabled' => true, 'percent' => 30])->assertStatus(401);
    }
}
