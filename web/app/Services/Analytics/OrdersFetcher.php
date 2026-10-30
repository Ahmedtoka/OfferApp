<?php

namespace App\Services\Analytics;

use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;

/**
 * Pages through the shop's recent orders (newest first) until the range is exhausted or the cap is hit.
 * Cancelled orders are excluded twice: in the search query and by `cancelledAt` on each node.
 * Refunded orders stay counted (the discount was granted at purchase); test orders are not excluded.
 */
class OrdersFetcher
{
    private const QUERY = <<<'GQL'
query AnalyticsOrders($cursor: String, $q: String!) {
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
          product { id title }
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

    public function __construct(private GraphqlClient $graphql) {}

    /**
     * @return array{orders: list<array>, capped: bool}
     *
     * @throws ShopifyGraphqlException
     */
    public function fetch(string $shop, int $rangeDays, int $cap): array
    {
        $q = 'created_at:>='.now()->subDays($rangeDays)->toIso8601String().' -status:cancelled';
        $orders = [];
        $cursor = null;

        do {
            $page = $this->graphql->query($shop, self::QUERY, ['cursor' => $cursor, 'q' => $q])['orders'] ?? [];
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

        return ['orders' => array_slice($orders, 0, $cap), 'capped' => $capped];
    }
}
