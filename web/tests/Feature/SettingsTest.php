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

    private const MESSAGE = 'تم الحفظ وتم تحديث الخصم في شوبيفاي';

    private FakeGraphqlClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        config(['shopify.function_title' => 'BOGO Discount (Le Voile)']);
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

    private function save(array $body)
    {
        return $this->auth()->putJson('/api/settings', $body);
    }

    public function test_save_success_writes_reads_back_and_ensures_discount(): void
    {
        $res = $this->save(['enabled' => true, 'percent' => 30]);

        $res->assertOk()
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 30)
            ->assertJsonPath('discount.id', 'gid://shopify/DiscountAutomaticNode/888')
            ->assertJsonPath('discount.status', 'ACTIVE')
            ->assertJsonPath('discount.adminUrl', 'https://admin.shopify.com/store/levoilestores/discounts/888');
        $this->assertNotNull($res->json('settings.updated_at'));
        $this->assertSame(
            ['ShopId', 'WriteSettings', 'ReadSettings', 'AppFunctions', 'AppDiscounts', 'CreateDiscount'],
            $this->fake->ops()
        );

        $write = $this->fake->calls[1]['variables']['metafields'][0];
        $this->assertSame('gid://shopify/Shop/90000000001', $write['ownerId']);
        $this->assertSame('$app:bogo', $write['namespace']);
        $this->assertSame('config', $write['key']);
        $this->assertSame('json', $write['type']);

        $create = $this->fake->calls[5]['variables']['d'];
        $this->assertSame('01BOGOFUNCTION', $create['functionId']);
        $this->assertSame(['PRODUCT'], $create['discountClasses']);
        $this->assertSame(['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false], $create['combinesWith']);
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
        $this->assertSame('اشتري واحدة وخد واحدة', $fake->calls[array_search('UpdateDiscount', $fake->ops(), true)]['variables']['d']['title']);
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
        $this->fake->readBackOverride = json_encode(['enabled' => true, 'percent' => 25, 'updated_at' => 'x']);

        $this->save(['enabled' => true, 'percent' => 30])
            ->assertStatus(422)
            ->assertExactJson(['error' => 'read-back mismatch']);
        $this->assertSame(['ShopId', 'WriteSettings', 'ReadSettings'], $this->fake->ops());
    }

    public function test_read_back_enabled_mismatch_is_also_rejected(): void
    {
        $this->fake->readBackOverride = json_encode(['enabled' => false, 'percent' => 30]);

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

    public function test_boundary_percents_are_accepted(): void
    {
        $this->save(['enabled' => false, 'percent' => 1])->assertOk()->assertJsonPath('settings.enabled', false);
        $this->save(['enabled' => true, 'percent' => 99])->assertOk()->assertJsonPath('settings.percent', 99);
    }

    public function test_get_returns_defaults_and_null_discount_without_creating(): void
    {
        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertExactJson(['settings' => ['enabled' => false, 'percent' => 30, 'updated_at' => null], 'discount' => null]);
        $this->assertSame(0, $this->fake->count('CreateDiscount'));
    }

    public function test_get_returns_saved_settings_and_existing_discount(): void
    {
        $this->save(['enabled' => true, 'percent' => 40])->assertOk();

        $this->auth()->getJson('/api/settings')
            ->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.percent', 40)
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
