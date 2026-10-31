<?php

namespace App\Http\Controllers;

use App\Offer\OfferConstants;
use App\Services\Analytics\OfferAnalytics;
use App\Shopify\ShopifyAccessDenied;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function show(Request $request, OfferAnalytics $analytics): JsonResponse
    {
        $ranges = array_map('intval', OfferConstants::get('analyticsRanges'));
        $data = $request->validate(['range' => ['sometimes', 'integer', Rule::in($ranges)]]);
        $range = (int) ($data['range'] ?? OfferConstants::get('defaultRange'));

        try {
            return response()->json($analytics->compute((string) $request->attributes->get('shop'), $range)->toArray());
        } catch (ShopifyAccessDenied) {
            // The orders scope is optional for the public app: the page asks the merchant to grant it.
            return response()->json(['error' => 'needs_read_orders'], 403);
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
