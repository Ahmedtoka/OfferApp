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

    /**
     * The HMAC covers the raw body, so the body is trusted: when it names a shop under $field, that shop
     * must be the header's. An absent field passes unless $required.
     */
    private function bodyMatches(string $normalized, Request $request, string $field, bool $required): bool
    {
        $value = $request->json($field);
        if ($value === null) {
            return ! $required;
        }

        return Shop::normalizeDomain((string) $value) === $normalized;
    }

    /** shop/redact (payload: shop_id, shop_domain): the shop's row and token are erased; nothing else is stored. */
    private function redact(string $domain, Request $request): void
    {
        $normalized = Shop::normalizeDomain($domain);
        if ($normalized === null || ! $this->bodyMatches($normalized, $request, 'shop_domain', true)) {
            return;
        }

        Shop::where('shop_domain', $normalized)->delete();
    }

    private function markUninstalled(string $domain, Request $request): void
    {
        $normalized = Shop::normalizeDomain($domain);
        if ($normalized === null || ! $this->bodyMatches($normalized, $request, 'myshopify_domain', false)) {
            return;
        }

        Shop::where('shop_domain', $normalized)->first()?->forceFill([
            'uninstalled_at' => now(),
            'access_token' => null,
        ])->save();
    }
}
