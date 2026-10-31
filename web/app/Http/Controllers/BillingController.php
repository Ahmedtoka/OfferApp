<?php

namespace App\Http\Controllers;

use App\Services\AlreadySubscribed;
use App\Services\Billing;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function show(Request $request, Billing $billing): JsonResponse
    {
        if (! Billing::required()) {
            return response()->json(['required' => false, 'active' => true, 'trialDaysLeft' => null, 'partnerDevelopment' => false]);
        }

        try {
            return response()->json(['required' => true] + $billing->status((string) $request->attributes->get('shop')));
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function subscribe(Request $request, Billing $billing): JsonResponse
    {
        if (! Billing::required()) {
            abort(404);
        }

        try {
            return response()->json(['confirmationUrl' => $billing->subscribe((string) $request->attributes->get('shop'))]);
        } catch (AlreadySubscribed $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (ShopifyGraphqlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
