<?php

namespace App\Shopify;

interface GraphqlClient
{
    /**
     * Run an Admin GraphQL query/mutation for an installed shop and return the decoded `data`.
     * Mutation `userErrors` are returned as data; callers check them.
     *
     * @throws ShopifyGraphqlException on HTTP != 200 or top-level `errors`
     */
    public function query(string $shop, string $query, array $variables = []): array;
}
