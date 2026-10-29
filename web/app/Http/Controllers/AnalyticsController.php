<?php

namespace App\Http\Controllers;

use App\Offer\OfferConstants;
use App\Services\Analytics\OfferAnalytics;
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
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
