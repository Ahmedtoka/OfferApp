<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SiteController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/` is two documents. A load carrying `hmac` or `id_token` (even a malformed one) is the embedded app and
 * goes on to VerifyAdminQueryHmac; anything else, on the site's host, is the public landing. Runs first so
 * that a failed Shopify check can never fall through to the landing.
 */
class ServeLanding
{
    public function handle(Request $request, Closure $next): Response
    {
        $embedded = $request->query->has('hmac') || $request->query->has('id_token');

        if ($embedded || strtolower($request->getHost()) !== SiteController::host()) {
            return $next($request);
        }

        return response(app(SiteController::class)->page($request, 'home', 'en'));
    }
}
