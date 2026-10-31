<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    private const COMPLIANCE_TOPICS = ['customers/data_request', 'customers/redact', 'shop/redact'];

    public function handle(Request $request): Response
    {
        $topic = (string) $request->header('X-Shopify-Topic');
        $domain = (string) $request->header('X-Shopify-Shop-Domain');

        if ($topic === 'app/uninstalled') {
            $this->markUninstalled($domain, $request);
        } elseif (in_array($topic, self::COMPLIANCE_TOPICS, true)) {
            // The app stores no customer data; log topic + shop only, never the body.
            Log::info("Compliance webhook received: {$topic}", ['shop' => $domain]);
            if ($topic === 'shop/redact') {
                $this->redact($domain, $request);
            }
        }

        return response('{}', 200, ['Content-Type' => 'application/json']);
    }

    /** The HMAC covers the raw body, so its myshopify_domain is trusted; it must match the header shop. */
    private function bodyMatches(?string $normalized, Request $request): bool
    {
        return $normalized !== null
            && Shop::normalizeDomain((string) $request->json('myshopify_domain')) === $normalized;
    }

    /** shop/redact: the shop's row (and its token) is erased; nothing else about the shop is stored. */
    private function redact(string $domain, Request $request): void
    {
        $normalized = Shop::normalizeDomain($domain);
        if (! $this->bodyMatches($normalized, $request)) {
            return;
        }

        Shop::where('shop_domain', $normalized)->delete();
    }

    private function markUninstalled(string $domain, Request $request): void
    {
        $normalized = Shop::normalizeDomain($domain);
        if (! $this->bodyMatches($normalized, $request)) {
            return;
        }

        Shop::where('shop_domain', $normalized)->first()?->forceFill([
            'uninstalled_at' => now(),
            'access_token' => null,
        ])->save();
    }
}
