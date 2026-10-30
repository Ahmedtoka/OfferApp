<?php

namespace App\Http\Controllers;

use App\Offer\OfferConstants;
use Illuminate\Contracts\View\View;

/**
 * The single embedded admin page. Renders only public values (the App Bridge api key
 * and the shared offer constants); all shop data is fetched by app.js over /api with
 * a session token.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $defaultPercent = (int) OfferConstants::get('defaultPercent');

        return view('home', [
            'apiKey' => (string) config('shopify.api_key'),
            'pageTitle' => (string) OfferConstants::get('discountTitle'),
            'percentTemplate' => '{pct}% off pieces',
            'percentLabel' => $defaultPercent.'% off pieces',
            'defaultPercent' => $defaultPercent,
            'minPercent' => (int) OfferConstants::get('minPercent'),
            'maxPercent' => (int) OfferConstants::get('maxPercent'),
            // Default only; app.js replaces it with the shop's saved tag from /api/settings.
            'eligibleTag' => (string) OfferConstants::get('eligibleTag'),
            'tagMaxLength' => 100,
            'ranges' => array_map('intval', (array) OfferConstants::get('analyticsRanges')),
            'defaultRange' => (int) OfferConstants::get('defaultRange'),
            'orderCap' => (int) OfferConstants::get('analyticsOrderCap'),
            'assetVersion' => (string) (@filemtime(public_path('app.js')) ?: '1'),
        ]);
    }
}
