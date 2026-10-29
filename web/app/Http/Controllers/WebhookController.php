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
            $this->markUninstalled($domain);
        } elseif (in_array($topic, self::COMPLIANCE_TOPICS, true)) {
            // The app stores no customer data; log topic + shop only, never the body.
            Log::info("Compliance webhook received: {$topic}", ['shop' => $domain]);
        }

        return response('{}', 200, ['Content-Type' => 'application/json']);
    }

    private function markUninstalled(string $domain): void
    {
        $normalized = Shop::normalizeDomain($domain);
        if ($normalized === null) {
            return;
        }

        Shop::where('shop_domain', $normalized)->first()?->forceFill([
            'uninstalled_at' => now(),
            'access_token' => null,
        ])->save();
    }
}
