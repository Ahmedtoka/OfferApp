<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Shopify\InvalidSessionToken;
use App\Shopify\SessionToken;
use App\Shopify\TokenExchange;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bearer session-token auth for /api/*. No cookie or session fallback.
 * First valid request from an allowed, not-yet-installed shop performs the token exchange (install).
 */
class VerifySessionToken
{
    public function __construct(private TokenExchange $exchange) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = (string) $request->bearerToken();

        try {
            $claims = SessionToken::verify(
                $jwt,
                (string) config('shopify.api_key'),
                (string) config('shopify.api_secret'),
                (array) config('shopify.allowed_shops'),
                time(),
            );
        } catch (InvalidSessionToken) {
            return self::unauthorized();
        }

        if (Shop::active($claims['shop']) === null && ! $this->install($claims['shop'], $jwt)) {
            return self::unauthorized();
        }

        $request->attributes->set('shop', $claims['shop']);

        return $next($request);
    }

    private function install(string $shop, string $jwt): bool
    {
        try {
            $token = $this->exchange->offlineToken($shop, $jwt);
        } catch (Throwable) {
            return false;
        }

        Shop::updateOrCreate(['shop_domain' => $shop], [
            'access_token' => $token['access_token'],
            'scopes' => $token['scope'],
            'installed_at' => now(),
            'uninstalled_at' => null,
        ]);

        return true;
    }

    public static function unauthorized(): Response
    {
        return response()->json(new \stdClass, 401);
    }
}
