<?php

namespace Tests\Support;

/**
 * Mints Shopify-style HS256 session tokens (JWT) for tests, using the same
 * base64url + hash_hmac construction the app verifies against.
 */
final class SessionTokens
{
    public const API_KEY = 'test-api-key';

    public const API_SECRET = 'test-api-secret';

    public const SHOP = 'levoilestores.myshopify.com';

    public const ALLOWED_SHOPS = ['levoilestores.myshopify.com', 'levoilest.myshopify.com'];

    /** Point config('shopify.*') at the test key, secret and allow-list. */
    public static function configure(): void
    {
        config([
            'shopify.api_key' => self::API_KEY,
            'shopify.api_secret' => self::API_SECRET,
            'shopify.allowed_shops' => self::ALLOWED_SHOPS,
        ]);
    }

    /** Default claims for SHOP, valid around $now; a null override removes the claim. */
    public static function claims(array $overrides = [], ?int $now = null): array
    {
        $now ??= time();
        $shop = self::SHOP;

        $claims = array_merge([
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => self::API_KEY,
            'sub' => '42',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now - 5,
            'jti' => bin2hex(random_bytes(8)),
            'sid' => 'session-id',
        ], $overrides);

        return array_filter($claims, fn ($v) => $v !== null);
    }

    public static function mint(array $overrides = [], ?int $now = null, string $secret = self::API_SECRET, array $header = ['alg' => 'HS256', 'typ' => 'JWT']): string
    {
        $head = self::b64(json_encode($header));
        $body = self::b64(json_encode(self::claims($overrides, $now)));
        $sig = self::b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true));

        return "{$head}.{$body}.{$sig}";
    }

    /** Shopify's admin query signature: sorted key=value pairs joined by '&'. */
    public static function signQuery(array $params, string $secret = self::API_SECRET): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }

        return hash_hmac('sha256', implode('&', $pairs), $secret);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
