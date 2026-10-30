<?php

use App\Http\Middleware\EmbeddedCsp;
use App\Http\Middleware\VerifyAdminQueryHmac;
use App\Http\Middleware\VerifySessionToken;
use App\Http\Middleware\VerifyWebhookHmac;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'shopify.session' => VerifySessionToken::class,
            'shopify.hmac' => VerifyAdminQueryHmac::class,
            'shopify.webhook' => VerifyWebhookHmac::class,
        ]);
        // Laravel's priority list would otherwise run ThrottleRequests before our auth, keying the
        // per-shop limiter by an empty shop (one bucket shared by every caller, unauthenticated included).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: VerifySessionToken::class);
        $middleware->validateCsrfTokens(except: ['webhooks']);
        // Global, so health checks, 404/405 and other error pages are covered too, not just the route groups.
        $middleware->append(EmbeddedCsp::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
