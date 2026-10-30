<?php

namespace Tests\Support;

use App\Shopify\GraphqlClient;

/**
 * Serves orders_*.json pages in sequence (one per call) and records every call. ReadSettings
 * (the shop config analytics reads for the eligible tag) is answered from $settingsValue and
 * recorded in $settingsReads, not in $calls, so page order and call indexes stay as before.
 */
class FakeOrdersClient implements GraphqlClient
{
    /** @var list<array{op: string, variables: array, shop: string}> */
    public array $calls = [];

    /** Raw JSON of the shop config metafield; null = not saved yet (default tag). */
    public ?string $settingsValue = null;

    public int $settingsReads = 0;

    /** @param  list<string>  $pages  fixture names under tests/Fixtures/graphql */
    public function __construct(private array $pages) {}

    public function query(string $shop, string $query, array $variables = []): array
    {
        preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $m);
        if (($m[1] ?? null) === 'ReadSettings') {
            $this->settingsReads++;

            return ['shop' => ['metafield' => $this->settingsValue === null ? null : ['value' => $this->settingsValue]]];
        }
        $this->calls[] = ['op' => $m[1] ?? 'unknown', 'variables' => $variables, 'shop' => $shop];

        $name = $this->pages[count($this->calls) - 1] ?? throw new \LogicException('No more fixture pages');

        return json_decode((string) file_get_contents(__DIR__."/../Fixtures/graphql/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }
}
