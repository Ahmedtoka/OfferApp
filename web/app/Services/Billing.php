<?php

namespace App\Services;

use App\Shopify\GraphqlClient;
use App\Shopify\ShopifyGraphqlException;
use Illuminate\Support\Carbon;

/**
 * One plan ("Pairly", $19.99 every 30 days, 14-day trial) through the Shopify Billing API.
 * Partner development stores need no subscription. Only turning the offer on is gated (see SettingsController).
 */
class Billing
{
    public const PLAN_NAME = 'Pairly';

    public const PRICE_USD = 19.99;

    public const TRIAL_DAYS = 14;

    private const STATUS = 'query BillingStatus { shop { plan { partnerDevelopment } } currentAppInstallation { activeSubscriptions { name status trialDays createdAt test } } }';

    private const CREATE = 'mutation CreateSubscription($name: String!, $returnUrl: URL!, $test: Boolean, $trialDays: Int, $lineItems: [AppSubscriptionLineItemInput!]!) { appSubscriptionCreate(name: $name, returnUrl: $returnUrl, test: $test, trialDays: $trialDays, lineItems: $lineItems) { confirmationUrl userErrors { field message } } }';

    public function __construct(private GraphqlClient $graphql) {}

    /** Older deployments that never subscribe set this to false and are not gated. */
    public static function required(): bool
    {
        return (bool) config('shopify.billing_required');
    }

    /**
     * `active` is true for a partner development store or with a counting ACTIVE subscription; `subscribed` only for the latter.
     *
     * @return array{active: bool, subscribed: bool, trialDaysLeft: ?int, partnerDevelopment: bool}
     *
     * @throws ShopifyGraphqlException
     */
    public function status(string $shop): array
    {
        $data = $this->graphql->query($shop, self::STATUS);
        $partner = (bool) ($data['shop']['plan']['partnerDevelopment'] ?? false);

        // On a live store (production, not a partner dev store) a test subscription is not a payment.
        $testCounts = $partner || config('app.env') !== 'production';

        $subscription = null;
        foreach ($data['currentAppInstallation']['activeSubscriptions'] ?? [] as $candidate) {
            if (($candidate['status'] ?? null) === 'ACTIVE' && ($testCounts || ($candidate['test'] ?? true) === false)) {
                $subscription = $candidate;
                break;
            }
        }

        $left = null;
        if ($subscription !== null) {
            $end = Carbon::parse($subscription['createdAt'])->addDays((int) ($subscription['trialDays'] ?? 0));
            $left = max(0, (int) ceil(now()->diffInSeconds($end, false) / 86400));
        }

        return ['active' => $partner || $subscription !== null, 'subscribed' => $subscription !== null, 'trialDaysLeft' => $left, 'partnerDevelopment' => $partner];
    }

    /**
     * Creates the subscription and returns the URL the merchant approves it at (opened at top level by the page).
     *
     * @throws AlreadySubscribed when the shop already has a subscription (no second subscription is created)
     * @throws ShopifyGraphqlException
     */
    public function subscribe(string $shop): string
    {
        $status = $this->status($shop);
        if ($status['subscribed']) {
            throw new AlreadySubscribed('This store already has a Pairly subscription.');
        }
        $partner = $status['partnerDevelopment'];
        $handle = preg_replace('/\.myshopify\.com$/', '', $shop);

        $data = $this->graphql->query($shop, self::CREATE, [
            'name' => self::PLAN_NAME,
            'returnUrl' => "https://admin.shopify.com/store/{$handle}/apps/".config('shopify.app_handle'),
            'test' => config('app.env') !== 'production' || $partner,
            'trialDays' => self::TRIAL_DAYS,
            'lineItems' => [[
                'plan' => ['appRecurringPricingDetails' => [
                    'price' => ['amount' => self::PRICE_USD, 'currencyCode' => 'USD'],
                    'interval' => 'EVERY_30_DAYS',
                ]],
            ]],
        ]);

        $errors = $data['appSubscriptionCreate']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyGraphqlException(implode('; ', array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $errors)));
        }

        return $data['appSubscriptionCreate']['confirmationUrl']
            ?? throw new ShopifyGraphqlException('Shopify did not return a confirmation URL.');
    }
}
