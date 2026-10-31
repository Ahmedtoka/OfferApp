<?php

namespace App\Shopify;

use App\Models\Shop;

/**
 * Verifies Shopify App Bridge session tokens (HS256 JWT signed with the app secret).
 * Every failure throws InvalidSessionToken with a generic reason and never the token.
 */
final class SessionToken
{
    /**
     * Seconds a token's "not before" may be ahead of this server's clock. Shopify mints tokens with
     * nbf = the current second on its side; a server clock even a fraction of a second behind would
     * otherwise reject a fresh token. Expiry stays strict.
     */
    public const NBF_CLOCK_TOLERANCE = 10;

    /** @return array{shop: string, sub: string} */
    public static function verify(string $jwt, string $apiKey, string $apiSecret, array $allowedShops, int $now): array
    {
        if ($apiSecret === '' || $apiKey === '') {
            throw new InvalidSessionToken('app credentials not configured');
        }

        $claims = self::decodeVerified($jwt, $apiSecret);
        self::checkTimes($claims, $now);

        if (! isset($claims['aud']) || ! is_string($claims['aud']) || ! hash_equals($apiKey, $claims['aud'])) {
            throw new InvalidSessionToken('audience mismatch');
        }

        $shop = self::shopFrom($claims, $allowedShops);

        if (! isset($claims['sub']) || ! (is_string($claims['sub']) || is_int($claims['sub']))) {
            throw new InvalidSessionToken('missing subject');
        }

        return ['shop' => $shop, 'sub' => (string) $claims['sub']];
    }

    private static function decodeVerified(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidSessionToken('malformed token');
        }
        [$head, $body, $sig] = $parts;

        $header = self::jsonPart($head);
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidSessionToken('unsupported algorithm');
        }

        $expected = self::b64encode(hash_hmac('sha256', "{$head}.{$body}", $secret, true));
        if (! hash_equals($expected, $sig)) {
            throw new InvalidSessionToken('bad signature');
        }

        return self::jsonPart($body);
    }

    private static function checkTimes(array $claims, int $now): void
    {
        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            throw new InvalidSessionToken('expired');
        }
        if (! is_int($claims['nbf'] ?? null) || $claims['nbf'] > $now + self::NBF_CLOCK_TOLERANCE) {
            throw new InvalidSessionToken('not yet valid');
        }
    }

    private static function shopFrom(array $claims, array $allowedShops): string
    {
        $dest = self::host($claims['dest'] ?? null);
        $iss = self::host($claims['iss'] ?? null);

        if ($dest === null || $iss === null || ! hash_equals($dest, $iss)) {
            throw new InvalidSessionToken('issuer/destination mismatch');
        }

        $shop = Shop::normalizeDomain($dest);
        if ($shop === null || ! Shop::inAllowList($shop, $allowedShops)) {
            throw new InvalidSessionToken('shop not allowed');
        }

        return $shop;
    }

    private static function host(mixed $url): ?string
    {
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private static function jsonPart(string $segment): array
    {
        $raw = base64_decode(strtr($segment, '-_', '+/'), true);
        $data = $raw === false ? null : json_decode($raw, true);
        if (! is_array($data)) {
            throw new InvalidSessionToken('malformed token');
        }

        return $data;
    }

    private static function b64encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
