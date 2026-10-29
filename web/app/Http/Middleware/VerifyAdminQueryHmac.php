<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Shopify\InvalidSessionToken;
use App\Shopify\SessionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the embedded page load. Accepts either a valid Shopify `hmac` over the query
 * string, or (when no hmac is present) a valid `id_token` session token.
 */
class VerifyAdminQueryHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query->all();

        $shop = match (true) {
            isset($query['hmac']) => $this->shopFromHmac($query),
            isset($query['id_token']) => $this->shopFromIdToken($query),
            default => null,
        };

        if ($shop === null) {
            return VerifySessionToken::unauthorized();
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    private function shopFromHmac(array $query): ?string
    {
        $secret = (string) config('shopify.api_secret');
        $hmac = $query['hmac'];
        unset($query['hmac']);

        if ($secret === '' || ! is_string($hmac) || ! is_string($query['shop'] ?? null)) {
            return null;
        }

        $message = $this->canonicalQuery($query);
        if ($message === null || ! hash_equals(hash_hmac('sha256', $message, $secret), $hmac)) {
            return null;
        }

        return Shop::isAllowed($query['shop']) ? Shop::normalizeDomain($query['shop']) : null;
    }

    private function shopFromIdToken(array $query): ?string
    {
        if (! is_string($query['id_token'])) {
            return null;
        }

        try {
            $shop = SessionToken::verify(
                $query['id_token'],
                (string) config('shopify.api_key'),
                (string) config('shopify.api_secret'),
                (array) config('shopify.allowed_shops'),
                time(),
            )['shop'];
        } catch (InvalidSessionToken) {
            return null;
        }

        $param = $query['shop'] ?? null;
        if ($param !== null && (! is_string($param) || Shop::normalizeDomain($param) !== $shop)) {
            return null;
        }

        return $shop;
    }

    /** Params sorted by key, joined as key=value with '&'. Null if any value is not scalar. */
    private function canonicalQuery(array $params): ?string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            if (! is_string($value)) {
                return null;
            }
            $pairs[] = "{$key}={$value}";
        }

        return implode('&', $pairs);
    }
}
