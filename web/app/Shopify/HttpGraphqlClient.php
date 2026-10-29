<?php

namespace App\Shopify;

use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpGraphqlClient implements GraphqlClient
{
    public function query(string $shop, string $query, array $variables = []): array
    {
        $installed = Shop::active($shop);
        if ($installed === null) {
            throw new ShopifyGraphqlException('Shop is not installed');
        }

        $payload = ['query' => $query];
        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        $version = config('shopify.api_version');
        try {
            $response = Http::withHeaders(['X-Shopify-Access-Token' => $installed->access_token])
                ->acceptJson()->asJson()->timeout(20)
                ->post("https://{$installed->shop_domain}/admin/api/{$version}/graphql.json", $payload);
        } catch (ConnectionException) {
            // Generic message: the underlying one may carry request details.
            throw new ShopifyGraphqlException('Shopify GraphQL connection failed');
        }

        return $this->dataFrom($response);
    }

    private function dataFrom(Response $response): array
    {
        if ($response->status() !== 200) {
            throw new ShopifyGraphqlException("Shopify GraphQL HTTP {$response->status()}");
        }

        $errors = $response->json('errors');
        if (! empty($errors)) {
            throw new ShopifyGraphqlException('Shopify GraphQL errors: '.$this->messages($errors));
        }

        return (array) $response->json('data', []);
    }

    private function messages(mixed $errors): string
    {
        if (! is_array($errors)) {
            return (string) $errors;
        }

        return implode('; ', array_map(
            fn ($e) => is_array($e) ? (string) ($e['message'] ?? 'unknown error') : (string) $e,
            $errors
        ));
    }
}
