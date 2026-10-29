<?php

namespace Tests\Unit\Shopify;

use App\Models\Shop;
use App\Shopify\GraphqlClient;
use App\Shopify\HttpGraphqlClient;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class HttpGraphqlClientTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://levoilestores.myshopify.com/admin/api/2026-07/graphql.json';

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        config(['shopify.api_version' => '2026-07']);
        Http::preventStrayRequests();
        Shop::create(['shop_domain' => SessionTokens::SHOP, 'access_token' => 'shpat_x', 'installed_at' => now()]);
    }

    public function test_container_binds_interface_to_http_client(): void
    {
        $this->assertInstanceOf(HttpGraphqlClient::class, app(GraphqlClient::class));
    }

    public function test_returns_data_and_sends_access_token(): void
    {
        Http::fake([self::URL => Http::response(['data' => ['shop' => ['name' => 'Le Voile']]])]);

        $data = app(GraphqlClient::class)->query(SessionTokens::SHOP, 'query($id: ID!) { shop { name } }', ['id' => 'gid://1']);

        $this->assertSame(['shop' => ['name' => 'Le Voile']], $data);
        Http::assertSent(fn (Request $r) => $r->url() === self::URL
            && $r->method() === 'POST'
            && $r->hasHeader('X-Shopify-Access-Token', 'shpat_x')
            && $r['variables'] === ['id' => 'gid://1']);
    }

    public function test_mutation_user_errors_are_returned_for_the_service_to_check(): void
    {
        Http::fake([self::URL => Http::response(['data' => ['discountAutomaticAppCreate' => ['userErrors' => [['message' => 'Title taken']]]]])]);

        $data = app(GraphqlClient::class)->query(SessionTokens::SHOP, 'mutation { x }');

        $this->assertSame('Title taken', $data['discountAutomaticAppCreate']['userErrors'][0]['message']);
    }

    public function test_throws_on_top_level_errors_with_messages(): void
    {
        Http::fake([self::URL => Http::response(['errors' => [['message' => 'Field x missing'], ['message' => 'Throttled']]])]);

        try {
            app(GraphqlClient::class)->query(SessionTokens::SHOP, '{ x }');
            $this->fail('expected exception');
        } catch (ShopifyGraphqlException $e) {
            $this->assertStringContainsString('Field x missing', $e->getMessage());
            $this->assertStringContainsString('Throttled', $e->getMessage());
            $this->assertStringNotContainsString('shpat_x', $e->getMessage());
        }
    }

    public function test_throws_on_non_200(): void
    {
        Http::fake([self::URL => Http::response(['errors' => 'Invalid API key or access token'], 401)]);

        $this->expectException(ShopifyGraphqlException::class);
        app(GraphqlClient::class)->query(SessionTokens::SHOP, '{ x }');
    }

    public function test_throws_for_shop_that_is_not_installed(): void
    {
        $this->expectException(ShopifyGraphqlException::class);
        app(GraphqlClient::class)->query('levoilest.myshopify.com', '{ x }');
    }
}
