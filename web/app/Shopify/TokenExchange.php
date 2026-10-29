<?php

namespace App\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Swaps a verified session token for an offline Admin API access token (managed install). */
class TokenExchange
{
    /** @return array{access_token: string, scope: string} */
    public function offlineToken(string $shop, string $sessionJwt): array
    {
        $domain = Shop::normalizeDomain($shop);
        if ($domain === null) {
            throw new RuntimeException('Token exchange refused: invalid shop domain');
        }

        $response = Http::acceptJson()->asJson()->timeout(15)
            ->post("https://{$domain}/admin/oauth/access_token", [
                'client_id' => (string) config('shopify.api_key'),
                'client_secret' => (string) config('shopify.api_secret'),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $sessionJwt,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Status only: the body may echo credentials.
            throw new RuntimeException("Token exchange failed (HTTP {$response->status()})");
        }

        return ['access_token' => $token, 'scope' => (string) $response->json('scope', '')];
    }
}
