<?php

namespace App\Shopify;

class WebhookSignature
{
    /** Shopify webhook HMAC: base64 of HMAC-SHA256 over the raw body, compared in constant time. */
    public static function valid(string $rawBody, string $headerHmac, string $secret): bool
    {
        if ($secret === '' || $headerHmac === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $rawBody, $secret, true)), $headerHmac);
    }
}
