<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The public marketing site: landing, pricing, docs and legal, in English (/...) and Arabic (/ar/...).
 * Everything rendered here is public; no shop data, no secret.
 */
class SiteController extends Controller
{
    public function page(Request $request, string $page, string $lang): View
    {
        app()->setLocale($lang);

        $site = self::baseUrl();
        $path = $page === 'home' ? '' : "/{$page}";
        $href = fn (string $target, ?string $language = null): string => $site.(($language ?? $lang) === 'ar' ? '/ar' : '').$target;

        return view("site.{$page}", [
            'lang' => $lang,
            'dir' => $lang === 'ar' ? 'rtl' : 'ltr',
            'page' => $page,
            'href' => $href,
            'canonical' => $lang === 'ar' ? $href($path, 'ar') : $href($path ?: '/', 'en'),
            'enUrl' => $href($path ?: '/', 'en'),
            'arUrl' => $href($path, 'ar'),
            'switchUrl' => $lang === 'ar' ? $href($path ?: '/', 'en') : $href($path, 'ar'),
            'installUrl' => (string) config('shopify.install_url') ?: $href('/docs#setup'),
            'legalName' => (string) config('shopify.legal_name'),
            'supportEmail' => (string) config('shopify.support_email'),
        ]);
    }

    /** SITE_URL, or APP_URL until the marketing domain exists; no trailing slash. */
    public static function baseUrl(): string
    {
        return rtrim((string) (config('shopify.site_url') ?: config('shopify.app_url')), '/');
    }

    /** Lower-cased host of the base URL, or null when neither URL is set. */
    public static function host(): ?string
    {
        $host = parse_url(self::baseUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
