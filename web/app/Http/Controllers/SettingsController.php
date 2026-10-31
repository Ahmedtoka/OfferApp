<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use App\Services\AutomaticDiscountManager;
use App\Services\OfferSettings;
use App\Services\OfferState;
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
            return response()->json($this->payload($settings->read($shop), $discounts->find($shop)));
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(SaveSettingsRequest $request, OfferSettings $settings): JsonResponse
    {
        $shop = (string) $request->attributes->get('shop');

        try {
            $result = $settings->save($shop, $request->offer());
        } catch (ShopifyGraphqlException|SaveMismatch $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->payload($result['settings'], $result['discount']) + ['message' => 'Saved. The discount in Shopify is up to date.']
        );
    }

    /** Settings plus the discount's dates (they live on the discount), and the combined state. */
    private function payload(array $settings, ?array $discount): array
    {
        return [
            'settings' => $settings + ['startsAt' => $discount['startsAt'] ?? null, 'endsAt' => $discount['endsAt'] ?? null],
            'discount' => $discount,
            'state' => OfferState::from($settings, $discount, now()),
        ];
    }
}
