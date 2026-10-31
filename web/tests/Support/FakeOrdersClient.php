<?php

namespace Tests\Support;

use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyAccessDenied;

/**
 * Serves orders pages in sequence (one per call) and records every call. A page is a fixture name under
 * tests/Fixtures/graphql or an inline array. The shop's currency is added to every page that lacks one.
 * ReadSettings (the shop config analytics reads) is answered from $settingsValue and recorded in
 * $settingsReads, and AnalyticsUnitCosts from $unitCosts in $costCalls; neither goes into $calls,
 * so page order and call indexes stay as before.
 */
class FakeOrdersClient implements GraphqlClient
{
    /** @var list<array{op: string, variables: array, shop: string, query: string}> */
    public array $calls = [];

    /** Raw JSON of the shop config metafield; null = not saved yet. */
    public ?string $settingsValue = null;

    public int $settingsReads = 0;

    public string $currency = 'EGP';

    /** true = Shopify refuses the orders query (read_orders not granted). */
    public bool $ordersDenied = false;

    /** variant gid => unit cost amount (null = no cost set). null instead of an array = read_inventory not granted. */
    public ?array $unitCosts = null;

    /** @var list<list<string>> variant ids asked for, per costs query */
    public array $costCalls = [];

    /** @param  list<string|array>  $pages */
    public function __construct(private array $pages) {}

    public function query(string $shop, string $query, array $variables = []): array
    {
        preg_match('/^\s*(?:query|mutation)\s+(\w+)/', $query, $m);
        if (($m[1] ?? null) === 'ReadSettings') {
            $this->settingsReads++;

            return ['shop' => ['metafield' => $this->settingsValue === null ? null : ['value' => $this->settingsValue]]];
        }
        if (($m[1] ?? null) === 'AnalyticsUnitCosts') {
            $this->costCalls[] = $variables['ids'];
            if ($this->unitCosts === null) {
                throw new ShopifyAccessDenied('Access denied for unitCost field. Required access: `read_inventory` access scope.');
            }

            return ['nodes' => array_map(fn (string $id) => [
                'id' => $id,
                'inventoryItem' => ['unitCost' => ($this->unitCosts[$id] ?? null) === null ? null : ['amount' => $this->unitCosts[$id]]],
            ], $variables['ids'])];
        }
        $this->calls[] = ['op' => $m[1] ?? 'unknown', 'variables' => $variables, 'shop' => $shop, 'query' => $query];
        if ($this->ordersDenied) {
            throw new ShopifyAccessDenied('Access denied for orders field. Required access: `read_orders` access scope.');
        }

        $page = $this->pages[count($this->calls) - 1] ?? throw new \LogicException('No more fixture pages');
        $data = is_array($page)
            ? $page
            : json_decode((string) file_get_contents(__DIR__."/../Fixtures/graphql/{$page}.json"), true, 512, JSON_THROW_ON_ERROR);
        $data['shop'] ??= ['currencyCode' => $this->currency];

        return $data;
    }
}
