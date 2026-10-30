<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use App\Services\AutomaticDiscountManager;
use App\Services\OfferSettings;
use App\Services\SaveMismatch;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request, OfferSettings $settings, AutomaticDiscountManager $discounts): JsonResponse
    {
        $shop = (string) $request->attributes->get('shop');

        try {
            return response()->json([
                'settings' => $settings->read($shop),
                'discount' => $discounts->find($shop),
            ]);
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(SaveSettingsRequest $request, OfferSettings $settings): JsonResponse
    {
        $shop = (string) $request->attributes->get('shop');

        try {
            $result = $settings->save(
                $shop,
                $request->boolean('enabled'),
                (int) $request->input('percent'),
                (string) $request->validated('eligibleTag'),
            );
        } catch (ShopifyGraphqlException|SaveMismatch $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($result + ['message' => 'Saved. The discount in Shopify is up to date.']);
    }
}
