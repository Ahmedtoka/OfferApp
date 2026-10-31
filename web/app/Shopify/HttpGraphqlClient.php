<?php

namespace App\Shopify;

use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpGraphqlClient implements GraphqlClient
{
    /** Backoff before each retry of a throttled request (microseconds): 500 ms, 1 s, 2 s. */
    private const BACKOFF_US = [500_000, 1_000_000, 2_000_000];

    /** @var callable(int): void */
    private $sleep;

    /** @param  (callable(int): void)|null  $sleep  receives microseconds; defaults to usleep (tests pass a no-op) */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static fn (int $us) => usleep($us);
    }

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
        $url = "https://{$installed->shop_domain}/admin/api/{$version}/graphql.json";

        // Throttled (HTTP 429 or a THROTTLED GraphQL error): retry up to 3 times with backoff.
        foreach ([...self::BACKOFF_US, null] as $backoff) {
            $response = $this->post($url, $installed->access_token, $payload);
            if ($backoff === null || ! $this->throttled($response)) {
                break;
            }
            ($this->sleep)($backoff);
        }

        return $this->dataFrom($response, $installed->shop_domain);
    }

    private function post(string $url, string $token, array $payload): Response
    {
        try {
            return Http::withHeaders(['X-Shopify-Access-Token' => $token])
                ->acceptJson()->asJson()->timeout(20)
                ->post($url, $payload);
        } catch (ConnectionException) {
            // Generic message: the underlying one may carry request details.
            throw new ShopifyGraphqlException('Shopify GraphQL connection failed');
        }
    }

    private function throttled(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }
        return $this->hasCode($response->json('errors'), 'THROTTLED');
    }

    private function hasCode(mixed $errors, string $code): bool
    {
        if (! is_array($errors)) {
            return false;
        }
        foreach ($errors as $e) {
            if (is_array($e) && ($e['extensions']['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    private function dataFrom(Response $response, string $shop): array
    {
        if ($response->status() === 401) {
            Shop::markTokenRevoked($shop);
            // Flag for VerifySessionToken: controllers catch ShopifyGraphqlException and answer 422,
            // but a revoked token must reach the page as 401 so it retries with a fresh session token.
            app()->instance(TokenRevoked::class, $shop);
            throw new TokenRevoked('Shopify access token was revoked');
        }
        if ($response->status() !== 200) {
            throw new ShopifyGraphqlException("Shopify GraphQL HTTP {$response->status()}");
        }

        $errors = $response->json('errors');
        if (! empty($errors)) {
            if ($this->hasCode($errors, 'ACCESS_DENIED')) {
                throw new ShopifyAccessDenied('Shopify GraphQL errors: '.$this->messages($errors));
            }
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
