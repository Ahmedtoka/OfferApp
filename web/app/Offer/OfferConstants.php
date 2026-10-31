<?php

namespace App\Offer;

/** Reads the constants shared with the Shopify Function (shared/offer-constants.json). */
final class OfferConstants
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public static function get(string $key): mixed
    {
        $path = self::sharedPath('offer-constants.json');

        if (! isset(self::$cache[$path])) {
            self::$cache[$path] = json_decode(
                (string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR
            );
        }

        return self::$cache[$path][$key];
    }

    /** Absolute path of a file in the shared/ folder; a relative configured path resolves against the app root. */
    public static function sharedPath(string $file): string
    {
        $configured = (string) config('shopify.shared_constants_path');
        if (! self::isAbsolute($configured)) {
            $configured = base_path($configured);
        }

        return dirname($configured).'/'.$file;
    }

    /** The title of the app's one automatic discount: SHOPIFY_DISCOUNT_TITLE, else the shared constant. */
    public static function discountTitle(): string
    {
        $configured = config('shopify.discount_title');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : (string) self::get('discountTitle');
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
