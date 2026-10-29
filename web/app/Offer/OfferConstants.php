<?php

namespace App\Offer;

/** Reads the constants shared with the Shopify Function (shared/offer-constants.json). */
final class OfferConstants
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public static function get(string $key): mixed
    {
        $path = (string) config('shopify.shared_constants_path');
        if (! self::isAbsolute($path)) {
            $path = base_path($path);
        }

        if (! isset(self::$cache[$path])) {
            self::$cache[$path] = json_decode(
                (string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR
            );
        }

        return self::$cache[$path][$key];
    }

    public static function percentMessage(int $pct): string
    {
        return str_replace('{pct}', (string) $pct, (string) self::get('percentMessageTemplate'));
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
