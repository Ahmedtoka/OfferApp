<?php

namespace Tests\Unit\Shopify;

use App\Models\Shop;
use App\Shopify\GraphqlClient;
use App\Shopify\HttpGraphqlClient;
use App\Shopify\ShopifyAccessDenied;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

    /** @var list<int> */
    private array $slept = [];

    private function client(): HttpGraphqlClient
    {
        return new HttpGraphqlClient(function (int $us) {
            $this->slept[] = $us;
        });
    }

    private static function throttledBody(): array
    {
        return ['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]];
    }

    public function test_throttled_twice_then_success_returns_data_after_three_calls(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(self::throttledBody())
            ->push('Too Many Requests', 429)
            ->push(['data' => ['shop' => ['name' => 'Le Voile']]])]);

        $data = $this->client()->query(SessionTokens::SHOP, '{ shop { name } }');

        $this->assertSame(['shop' => ['name' => 'Le Voile']], $data);
        Http::assertSentCount(3);
        $this->assertSame([500_000, 1_000_000], $this->slept);
    }

    public function test_throttled_four_times_throws_after_three_retries(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(self::throttledBody())
            ->push(self::throttledBody())
            ->push(self::throttledBody())
            ->push(self::throttledBody())]);

        try {
            $this->client()->query(SessionTokens::SHOP, '{ x }');
            $this->fail('expected exception');
        } catch (ShopifyGraphqlException $e) {
            $this->assertStringContainsString('Throttled', $e->getMessage());
        }
        Http::assertSentCount(4);
        $this->assertSame([500_000, 1_000_000, 2_000_000], $this->slept);
    }

    public function test_http_429_four_times_throws(): void
    {
        Http::fake([self::URL => Http::response('Too Many Requests', 429)]);

        $this->expectException(ShopifyGraphqlException::class);
        try {
            $this->client()->query(SessionTokens::SHOP, '{ x }');
        } finally {
            Http::assertSentCount(4);
        }
    }

    public function test_non_throttle_error_is_not_retried(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['errors' => [['message' => 'Field x missing', 'extensions' => ['code' => 'undefinedField']]]])
            ->push(['data' => ['x' => 1]])]);

        try {
            $this->client()->query(SessionTokens::SHOP, '{ x }');
            $this->fail('expected exception');
        } catch (ShopifyGraphqlException $e) {
            $this->assertStringContainsString('Field x missing', $e->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertSame([], $this->slept);
    }

    public function test_access_denied_error_gets_its_own_exception(): void
    {
        Http::fake([self::URL => Http::response(['errors' => [['message' => 'Access denied for orders field.', 'extensions' => ['code' => 'ACCESS_DENIED']]]])]);

        try {
            $this->client()->query(SessionTokens::SHOP, '{ x }');
            $this->fail('expected exception');
        } catch (ShopifyAccessDenied $e) {
            $this->assertStringContainsString('Access denied for orders', $e->getMessage());
        }
        Http::assertSentCount(1);
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

    public function test_connection_failure_is_wrapped_without_token(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('cURL error 28: timed out for '.self::URL)]);

        try {
            app(GraphqlClient::class)->query(SessionTokens::SHOP, '{ x }');
            $this->fail('expected exception');
        } catch (ShopifyGraphqlException $e) {
            $this->assertStringNotContainsString('shpat_x', $e->getMessage());
        }
    }

    public function test_throws_for_shop_that_is_not_installed(): void
    {
        $this->expectException(ShopifyGraphqlException::class);
        app(GraphqlClient::class)->query('levoilest.myshopify.com', '{ x }');
    }
}
