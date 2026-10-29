<?php

namespace App\Http\Middleware;

use App\Shopify\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $valid = WebhookSignature::valid(
            $request->getContent(),
            (string) $request->header('X-Shopify-Hmac-Sha256', ''),
            (string) config('shopify.api_secret'),
        );

        if (! $valid) {
            Log::warning('Webhook rejected: invalid HMAC');

            return response('{}', 401, ['Content-Type' => 'application/json']);
        }

        return $next($request);
    }
}
