<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Shopify\GraphqlClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SessionTokens;
use Tests\TestCase;

class TestCartTest extends TestCase
{
    use RefreshDatabase;

    private const V = 'gid://shopify/ProductVariant/';

    /** @var list<array{op: string, variables: array, query: string}> */
    private array $calls = [];

    /** variant gid => node (absent = null, as Shopify answers for a variant of another shop). */
    private array $variants = [];

    private string $settings = '';

    protected function setUp(): void
    {
        parent::setUp();
        SessionTokens::configure();
        Cache::flush();
        Shop::create([
            'shop_domain' => SessionTokens::SHOP,
            'access_token' => 'shpat_existing',
            'scopes' => 'read_products',
            'installed_at' => now(),
        ]);
        $this->settings = json_encode(['enabled' => true, 'percent' => 30, 'method' => 'customer', 'appliesTo' => 'tag', 'eligibleTag' => 'FallOffer', 'collectionId' => null]);
        $test = $this;
        $this->app->instance(GraphqlClient::class, new class($test) implements GraphqlClient
        {
            public function __construct(private TestCartTest $t) {}

            public function query(string $shop, string $query, array $variables = []): array
            {
                return $this->t->answer($query, $variables);
            }
        });
    }

    /** Called by the fake client. */
    public function answer(string $query, array $variables): array
    {
        preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $m);
        $op = $m[1] ?? 'unknown';
        $this->calls[] = ['op' => $op, 'variables' => $variables, 'query' => $query];
        if ($op === 'ReadSettings') {
            return ['shop' => ['metafield' => $this->settings === '' ? null : ['value' => $this->settings]]];
        }
        if ($op === 'TestCartVariants') {
            return [
                'shop' => ['currencyCode' => 'EGP'],
                'nodes' => array_map(fn (string $id) => $this->variants[$id] ?? null, $variables['ids']),
            ];
        }
        throw new \LogicException("Unexpected GraphQL operation {$op}");
    }

    private function variant(int $n, string $price, array $o = []): string
    {
        $id = self::V.$n;
        $this->variants[$id] = [
            'id' => $id,
            'title' => 'Default Title',
            'price' => $price,
            'compareAtPrice' => $o['compareAt'] ?? null,
            'product' => [
                'title' => $o['title'] ?? "Product {$n}",
                'isGiftCard' => $o['giftCard'] ?? false,
                'hasTag' => $o['tagged'] ?? true,
                'inCollection' => $o['inCollection'] ?? true,
            ],
        ];

        return $id;
    }

    private function cart(array $body)
    {
        return $this->withHeader('Authorization', 'Bearer '.SessionTokens::mint())->postJson('/api/test-cart', $body);
    }

    private function variantCalls(): int
    {
        return count(array_filter($this->calls, fn ($c) => $c['op'] === 'TestCartVariants'));
    }

    public function test_abc_returns_both_methods_with_the_chosen_split(): void
    {
        $a = $this->variant(1, '1200.00');
        $b = $this->variant(2, '1100.00');
        $c = $this->variant(3, '900.00');

        $res = $this->cart(['lines' => [
            ['variantId' => $a, 'quantity' => 1],
            ['variantId' => $b, 'quantity' => 1],
            ['variantId' => $c, 'quantity' => 1],
        ]]);

        $res->assertOk()->assertJsonPath('ineligible', []);
        $this->assertEquals(1830, $res->json('customer.totalPay'));
        $this->assertEquals(1370, $res->json('customer.totalDiscount'));
        $this->assertEquals(1970, $res->json('company.totalPay'));
        $this->assertSame('EGP', $res->json('currency'));
        $this->assertSame(30, $res->json('percent'));
        $this->assertCount(3, $res->json('customer.units'));
        $this->assertCount(3, $res->json('customer.candidates'));
        $chosen = array_values(array_filter($res->json('customer.candidates'), fn ($c) => $c['chosen']));
        $this->assertCount(1, $chosen);
        $this->assertEquals(1830, $chosen[0]['totalPay']);
        $this->assertSame('Product 3', $chosen[0]['single']);
        $this->assertSame(['Product 2'], $chosen[0]['gifts']);
        $roles = array_column($res->json('customer.units'), 'role');
        sort($roles);
        $this->assertSame(['gift', 'paid', 'single'], $roles);
    }

    public function test_variant_without_the_tag_is_ineligible_and_not_counted(): void
    {
        $a = $this->variant(1, '1200.00');
        $b = $this->variant(2, '1100.00', ['tagged' => false, 'title' => 'Plain mug']);
        $c = $this->variant(3, '900.00');

        $res = $this->cart(['lines' => [
            ['variantId' => $a, 'quantity' => 1],
            ['variantId' => $b, 'quantity' => 1],
            ['variantId' => $c, 'quantity' => 1],
        ]])->assertOk();

        $res->assertJsonPath('ineligible', [['title' => 'Plain mug', 'reason' => 'not_tagged']]);
        $this->assertCount(2, $res->json('customer.units'));
        $this->assertEquals(1200, $res->json('customer.totalPay'));
    }

    public function test_rejects_a_total_quantity_above_200(): void
    {
        $this->cart(['lines' => [
            ['title' => 'X', 'price' => '10', 'quantity' => 150],
            ['title' => 'Y', 'price' => '10', 'quantity' => 51],
        ]])->assertStatus(422)->assertJsonValidationErrors('lines');

        $this->cart(['lines' => [
            ['title' => 'X', 'price' => '10', 'quantity' => 150],
            ['title' => 'Y', 'price' => '10', 'quantity' => 50],
        ]])->assertOk();
    }

    public function test_variant_of_another_shop_is_not_found(): void
    {
        $res = $this->cart(['lines' => [['variantId' => self::V.'999', 'quantity' => 1]]])->assertOk();

        $res->assertJsonPath('ineligible.0.reason', 'not_found');
        $this->assertSame([], $res->json('customer.units'));
        $this->assertEquals(0, $res->json('customer.totalPay'));
    }

    public function test_gift_card_and_collection_reasons(): void
    {
        $this->settings = json_encode(['percent' => 30, 'method' => 'customer', 'appliesTo' => 'collection', 'eligibleTag' => '', 'collectionId' => 'gid://shopify/Collection/5']);
        $g = $this->variant(1, '50.00', ['giftCard' => true, 'title' => 'Card']);
        $o = $this->variant(2, '50.00', ['inCollection' => false, 'title' => 'Outside']);
        $i = $this->variant(3, '50.00');

        $res = $this->cart(['lines' => [
            ['variantId' => $g, 'quantity' => 1],
            ['variantId' => $o, 'quantity' => 1],
            ['variantId' => $i, 'quantity' => 1],
        ]])->assertOk();

        $res->assertJsonPath('ineligible', [
            ['title' => 'Card', 'reason' => 'gift_card'],
            ['title' => 'Outside', 'reason' => 'not_in_collection'],
        ]);
        $this->assertCount(1, $res->json('customer.units'));
    }

    public function test_variant_with_no_rule_saved_is_no_rule_but_typed_lines_count(): void
    {
        $this->settings = '';
        $v = $this->variant(1, '50.00', ['title' => 'Mug']);

        $res = $this->cart(['lines' => [
            ['variantId' => $v, 'quantity' => 1],
            ['title' => 'Typed', 'price' => '40', 'quantity' => 2],
        ]])->assertOk();

        $res->assertJsonPath('ineligible', [['title' => 'Mug', 'reason' => 'no_rule']]);
        $this->assertCount(2, $res->json('customer.units'));
        $this->assertEquals(40, $res->json('customer.totalPay'));
    }

    public function test_typed_lines_use_compare_at_for_the_lone_piece_and_make_no_lookup(): void
    {
        $res = $this->cart(['lines' => [['title' => 'Sale item', 'price' => '70', 'compareAt' => '100', 'quantity' => 1]]])->assertOk();

        $this->assertSame(0, $this->variantCalls());
        $this->assertEquals(70, $res->json('customer.totalPay')); // 30% off the full 100 is 70: no extra discount
        $this->assertEquals(0, $res->json('customer.totalDiscount'));
    }

    public function test_candidates_are_capped_at_25_keeping_the_chosen_one(): void
    {
        $lines = [];
        for ($n = 1; $n <= 31; $n++) {
            $lines[] = ['title' => "P{$n}", 'price' => (string) (10 + $n), 'quantity' => 1];
        }
        $res = $this->cart(['lines' => $lines])->assertOk();

        $this->assertCount(25, $res->json('customer.candidates'));
        $this->assertSame(31, $res->json('customer.candidatesTotal'));
        $this->assertTrue($res->json('customer.candidatesCapped'));
        $this->assertCount(1, array_filter($res->json('customer.candidates'), fn ($c) => $c['chosen']));
    }

    public function test_validation(): void
    {
        $v = $this->variant(1, '10.00');
        $bad = [
            [],
            ['lines' => []],
            ['lines' => [['title' => 'A', 'price' => '10', 'quantity' => 0]]],
            ['lines' => [['title' => 'A', 'price' => '10', 'quantity' => 1.5]]],
            ['lines' => [['title' => 'A', 'price' => '-1', 'quantity' => 1]]],
            ['lines' => [['title' => 'A', 'price' => 'abc', 'quantity' => 1]]],
            ['lines' => [['title' => 'A', 'quantity' => 1]]],
            ['lines' => [['price' => '10', 'quantity' => 1]]],
            ['lines' => [['variantId' => 'gid://shopify/Product/1', 'quantity' => 1]]],
            ['lines' => array_fill(0, 51, ['variantId' => $v, 'quantity' => 1])],
        ];
        foreach ($bad as $i => $body) {
            $this->cart($body)->assertStatus(422, "case {$i}");
        }
        $this->assertSame(0, $this->variantCalls());
    }

    public function test_requires_a_session_and_is_throttled_per_shop(): void
    {
        $this->postJson('/api/test-cart', ['lines' => []])->assertStatus(401);

        $body = ['lines' => [['title' => 'A', 'price' => '10', 'quantity' => 1]]];
        for ($i = 0; $i < 30; $i++) {
            $this->cart($body)->assertOk();
        }
        $this->cart($body)->assertStatus(429);
    }
}
