<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only the verified shop's admin frame the app. The shop is known only after
 * VerifyAdminQueryHmac / VerifySessionToken set the `shop` request attribute.
 */
class EmbeddedCsp
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = $request->attributes->get('shop');
        $policy = is_string($shop) && $shop !== ''
            ? "frame-ancestors https://{$shop} https://admin.shopify.com;"
            : "frame-ancestors 'none';";

        $response->headers->set('Content-Security-Policy', $policy);
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
