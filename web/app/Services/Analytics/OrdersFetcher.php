<?php

namespace App\Services\Analytics;

use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyAccessDenied;
use App\Shopify\ShopifyGraphqlException;

/**
 * Pages through the shop's recent orders (newest first) until the range is exhausted or the cap is hit.
 * Cancelled orders are excluded twice: in the search query and by `cancelledAt` on each node.
 * Refunded orders stay counted (the discount was granted at purchase); test orders are not excluded.
 * With a collection rule each product also says whether it is in that collection.
 */
class OrdersFetcher
{
    /** %1$s = extra variable declaration, %2$s = extra product field. */
    private const QUERY = <<<'GQL'
query AnalyticsOrders($cursor: String, $q: String!%1$s) {
  shop { currencyCode }
  orders(first: 250, after: $cursor, query: $q, sortKey: CREATED_AT, reverse: true) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      name
      createdAt
      cancelledAt
      lineItems(first: 250) {
        nodes {
          quantity
          originalUnitPriceSet { shopMoney { amount } }
          variant { id compareAtPrice }
          product { id title tags%2$s }
          discountAllocations {
            allocatedAmountSet { shopMoney { amount } }
            discountApplication {
              __typename
              ... on AutomaticDiscountApplication { title }
              ... on DiscountCodeApplication { code }
              ... on ManualDiscountApplication { title }
            }
          }
        }
      }
    }
  }
}
GQL;

    /** Needs the optional read_inventory scope; a separate query so a refusal leaves the rest of analytics intact. */
    private const COSTS = <<<'GQL'
query AnalyticsUnitCosts($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on ProductVariant { id inventoryItem { unitCost { amount } } }
  }
}
GQL;

    private const COSTS_PER_QUERY = 250;

    public function __construct(private GraphqlClient $graphql) {}

    /**
     * @return array{orders: list<array>, capped: bool, currency: string}
     *
     * @throws ShopifyGraphqlException|ShopifyAccessDenied
     */
    public function fetch(string $shop, int $rangeDays, int $cap, ?string $collectionId = null): array
    {
        $q = 'created_at:>='.now()->subDays($rangeDays)->toIso8601String().' -status:cancelled';
        $query = $collectionId === null
            ? sprintf(self::QUERY, '', '')
            : sprintf(self::QUERY, ', $collectionId: ID!', ' inCollection(id: $collectionId)');
        $variables = ['q' => $q] + ($collectionId === null ? [] : ['collectionId' => $collectionId]);
        $orders = [];
        $cursor = null;
        $currency = null;

        do {
            $data = $this->graphql->query($shop, $query, ['cursor' => $cursor] + $variables);
            $currency ??= $data['shop']['currencyCode'] ?? null;
            $page = $data['orders'] ?? [];
            foreach ($page['nodes'] ?? [] as $node) {
                if (($node['cancelledAt'] ?? null) !== null) {
                    continue;
                }
                $orders[] = $node;
            }
            $more = (bool) ($page['pageInfo']['hasNextPage'] ?? false);
            $cursor = $page['pageInfo']['endCursor'] ?? null;
        } while ($more && $cursor !== null && count($orders) < $cap);

        $capped = count($orders) > $cap || (count($orders) === $cap && $more);

        return [
            'orders' => array_slice($orders, 0, $cap),
            'capped' => $capped,
            'currency' => (string) ($currency ?? throw new ShopifyGraphqlException('Could not read the shop currency.')),
        ];
    }

    /**
     * Unit cost per variant id (decimal string; null = no cost set), or null when the app has no read_inventory.
     *
     * @param  list<string>  $variantIds
     * @return ?array<string, ?string>
     *
     * @throws ShopifyGraphqlException any failure other than the scope being missing
     */
    public function unitCosts(string $shop, array $variantIds): ?array
    {
        $costs = [];
        try {
            foreach (array_chunk(array_values(array_unique($variantIds)), self::COSTS_PER_QUERY) as $ids) {
                foreach ($this->graphql->query($shop, self::COSTS, ['ids' => $ids])['nodes'] ?? [] as $node) {
                    if (isset($node['id'])) {
                        $costs[$node['id']] = $node['inventoryItem']['unitCost']['amount'] ?? null;
                    }
                }
            }
        } catch (ShopifyAccessDenied) {
            return null;
        }

        return $costs;
    }
}
