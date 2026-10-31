<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = ['shop_domain', 'access_token', 'scopes', 'installed_at', 'uninstalled_at'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'installed_at' => 'datetime',
            'uninstalled_at' => 'datetime',
        ];
    }

    /** Lower-case + trim; returns null unless it is a bare *.myshopify.com host. */
    public static function normalizeDomain(string $domain): ?string
    {
        $d = strtolower(trim($domain));

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $d) ? $d : null;
    }

    /** An empty allow-list means any valid *.myshopify.com store may use the app. */
    public static function isAllowed(string $domain): bool
    {
        $d = self::normalizeDomain($domain);

        return $d !== null && self::inAllowList($d, (array) config('shopify.allowed_shops'));
    }

    /** True when the (already normalized) domain is listed, or when the list is empty (open app). */
    public static function inAllowList(string $domain, array $allowedShops): bool
    {
        return $allowedShops === [] || in_array($domain, $allowedShops, true);
    }

    public static function active(string $domain): ?self
    {
        $d = self::normalizeDomain($domain);
        if ($d === null) {
            return null;
        }

        return static::where('shop_domain', $d)
            ->whereNotNull('installed_at')
            ->whereNull('uninstalled_at')
            ->first();
    }
}
