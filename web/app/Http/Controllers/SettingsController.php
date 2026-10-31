<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSettingsRequest;
use App\Services\AutomaticDiscountManager;
use App\Services\Billing;
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
            return response()->json($this->payload($shop, $settings, $settings->read($shop), $discounts->find($shop)));
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(SaveSettingsRequest $request, OfferSettings $settings, Billing $billing): JsonResponse
    {
        $shop = (string) $request->attributes->get('shop');
        $offer = $request->offer();

        try {
            // Only turning the offer on is gated; turning it off is always allowed.
            if ($offer['enabled'] && Billing::required() && ! $billing->status($shop)['active']) {
                return response()->json(['error' => 'Subscribe to the '.Billing::PLAN_NAME.' plan to turn the offer on.'], 402);
            }

            $result = $settings->save($shop, $offer);
        } catch (ShopifyGraphqlException|SaveMismatch $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(
            $this->payload($shop, $settings, $result['settings'], $result['discount']) + ['message' => 'Saved. The discount in Shopify is up to date.']
        );
    }

    /** Settings plus the discount's dates (they live on the discount), and the combined state. */
    private function payload(string $shop, OfferSettings $store, array $settings, ?array $discount): array
    {
        [$title, $missing] = $this->collectionTitle($shop, $store, $settings);

        return [
            'collectionTitle' => $title,
            'collectionMissing' => $missing,
            'settings' => $settings + ['startsAt' => $discount['startsAt'] ?? null, 'endsAt' => $discount['endsAt'] ?? null],
            'discount' => $discount,
            'state' => OfferState::from($settings, $discount, now()),
        ];
    }

    /**
     * Only a collection rule has a title. A lookup that fails is "unknown" (no title, not missing), so a Shopify
     * hiccup never tells the merchant their collection was deleted.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function collectionTitle(string $shop, OfferSettings $store, array $settings): array
    {
        if (($settings['appliesTo'] ?? 'tag') !== 'collection' || ! is_string($settings['collectionId'] ?? null)) {
            return [null, false];
        }

        try {
            $title = $store->collectionTitle($shop, $settings['collectionId']);
        } catch (ShopifyGraphqlException) {
            return [null, false];
        }

        return [$title, $title === null];
    }
}
