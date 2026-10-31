<?php

namespace App\Http\Controllers;

use App\Offer\OfferConstants;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The single embedded admin document (Home, Settings, Analytics and Test are client-side sections of it).
 * Renders only public values (the App Bridge api key, the shared offer constants and the page strings in the
 * merchant's language); all shop data is fetched by app.js over /api with a session token.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Shopify passes the merchant's admin language as `locale` (e.g. ar, ar-EG): Arabic is RTL, anything else English.
        $isArabic = strtolower((string) preg_split('/[-_]/', (string) $request->query('locale', ''))[0]) === 'ar';
        $lang = $isArabic ? 'ar' : 'en';
        app()->setLocale($lang);

        $strings = ['locale' => $lang, 'dir' => $isArabic ? 'rtl' : 'ltr', 't' => trans('app')];

        return view('home', [
            'lang' => $lang,
            'dir' => $strings['dir'],
            'i18nJson' => json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
            'apiKey' => (string) config('shopify.api_key'),
            'pageTitle' => (string) config('shopify.app_name'),
            'themeHandle' => 'app-embed',
            'defaultPercent' => (int) OfferConstants::get('defaultPercent'),
            'minPercent' => (int) OfferConstants::get('minPercent'),
            'maxPercent' => (int) OfferConstants::get('maxPercent'),
            'methods' => array_map('strval', (array) OfferConstants::get('methods')),
            'defaultMethod' => (string) OfferConstants::get('defaultMethod'),
            'tagMaxLength' => 100,
            'ranges' => array_map('intval', (array) OfferConstants::get('analyticsRanges')),
            'defaultRange' => (int) OfferConstants::get('defaultRange'),
            'orderCap' => (int) OfferConstants::get('analyticsOrderCap'),
            'assetVersion' => (string) (@filemtime(public_path('app.js')) ?: '1'),
        ]);
    }
}
