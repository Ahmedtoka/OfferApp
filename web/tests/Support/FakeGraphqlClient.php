<?php

namespace Tests\Support;

use App\Shopify\GraphqlClient;

/**
 * Records Admin GraphQL calls (operation name taken from the query text) and
 * replays tests/Fixtures/graphql/*.json. Stateful just enough to behave like a
 * shop: the metafield written by WriteSettings is what ReadSettings returns,
 * unless $readBackOverride is set; a created discount is found afterwards, and an
 * activated one is reported ACTIVE afterwards.
 */
class FakeGraphqlClient implements GraphqlClient
{
    /** @var list<array{op: string, variables: array}> */
    public array $calls = [];

    /** Fixture served for each operation; override per test. */
    public array $fixtures = [
        'ShopId' => 'shop-id',
        'WriteSettings' => 'metafields-set-ok',
        'AppFunctions' => 'functions',
        'AppDiscounts' => 'discount-nodes-empty',
        'CreateDiscount' => 'discount-create-ok',
        'UpdateDiscount' => 'discount-update-ok',
        'ActivateDiscount' => 'discount-activate-ok',
    ];

    /** Raw JSON string ReadSettings returns instead of the last write (null = echo the write). */
    public ?string $readBackOverride = null;

    public ?string $metafieldValue = null;

    /** AppDiscounts fixture served after a successful ActivateDiscount. */
    public string $afterActivate = 'discount-nodes-existing';

    public function query(string $shop, string $query, array $variables = []): array
    {
        preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $m);
        $op = $m[1] ?? 'unknown';
        $this->calls[] = ['op' => $op, 'variables' => $variables];

        if ($op === 'WriteSettings') {
            $data = $this->fixture($this->fixtures[$op]);
            if (($data['metafieldsSet']['userErrors'] ?? []) === []) {
                $this->metafieldValue = $variables['metafields'][0]['value'];
            }

            return $data;
        }

        if ($op === 'ReadSettings') {
            $value = $this->readBackOverride ?? $this->metafieldValue;

            return ['shop' => ['metafield' => $value === null ? null : ['value' => $value]]];
        }

        if ($op === 'CreateDiscount') {
            $data = $this->fixture($this->fixtures[$op]);
            if (($data['discountAutomaticAppCreate']['userErrors'] ?? []) === []) {
                // From now on the discount exists (as the real shop would report it).
                $this->fixtures['AppDiscounts'] = 'discount-nodes-existing';
            }

            return $data;
        }

        if ($op === 'ActivateDiscount') {
            $data = $this->fixture($this->fixtures[$op]);
            if (($data['discountAutomaticActivate']['userErrors'] ?? []) === []) {
                // Once activated, the shop reports the discount ACTIVE (unless a test pins it).
                $this->fixtures['AppDiscounts'] = $this->afterActivate;
            }

            return $data;
        }

        return $this->fixture($this->fixtures[$op] ?? throw new \LogicException("Unexpected GraphQL operation {$op}"));
    }

    /** @return list<string> */
    public function ops(): array
    {
        return array_column($this->calls, 'op');
    }

    public function count(string $op): int
    {
        return count(array_filter($this->ops(), fn ($o) => $o === $op));
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/../Fixtures/graphql/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }
}
