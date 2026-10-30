# Spike: billing before distribution (2026-10-31)

Question: can billing be tested on a dev store before Public distribution is chosen (the choice is permanent)?
Method: docs research only. No app created, no Dev Dashboard change, no sign-in, no install.

## What the docs state
- Billing overview (https://shopify.dev/docs/apps/launch/billing): all App Store apps must use "a Shopify provided billing solution". Shopify App Pricing (managed pricing) is the default for new public apps; the Billing API ("Manual Pricing") is called legacy but still supported. The page does not say which distribution the Billing API needs, nor mention custom or no-distribution apps.
- Managed pricing (https://shopify.dev/docs/apps/launch/billing/managed-pricing): plans are configured on the app's App Store listing in Partner Dashboard. Dev-store testing is free. A $0 private test plan lets you test before you publish a plan. Docs do not say whether a non-public app can use it. USD only, recurring and usage plans, no one-time. After opting in, new recurring charges via the Billing API are blocked. The Partner API `activeSubscription` "only works for public apps".
- Distribution (https://shopify.dev/docs/apps/launch/distribution): "You can't change the distribution method after you select it." Custom distribution: merchants can't be charged through Shopify's billing. The page says nothing about an app with no distribution selected.
- Time-based subscriptions guide (https://shopify.dev/docs/apps/launch/billing/subscription-billing/create-time-based-subscriptions): prerequisite is only authenticated Admin GraphQL access; no distribution requirement stated; `test: true` not mentioned.

## What community posts report (not official, unverified)
- https://community.shopify.dev/t/how-to-test-the-graphql-billing-api-in-apps/542 : a custom app calling `appSubscriptionCreate` got "Apps without a public distribution cannot use the Billing API." Fix reported (Oct 2024): choose Public distribution in the Partner admin; the app need not be listed. A June 2025 post says it still failed while the app was in Draft. Test charges via the `test` argument reported by a non-staff user.

## Answers
- Billing API with no distribution or custom distribution: docs silent on none; community reports an error; custom distribution is documented as not chargeable. Inference: it does not work.
- Managed pricing needs listing: docs do not require a published listing (test plans work "before you publish a plan"), but plans live on the listing, so a listing record (Public distribution) is implied. Inference.
- Does Shopify require one route for App Store apps: it requires "a Shopify provided billing solution", not specifically one; managed pricing is the default and recommended, Billing API still supported.

## Conclusion
Billing cannot, per available evidence, be tested before choosing Public distribution by either route. Docs do not settle the no-distribution case; only a live experiment would.

## Experiment needed (not run, needs owner approval)
On a throwaway dev app (not the production app) with no distribution selected, run `appSubscriptionCreate` with `test: true` against `levoilest.myshopify.com` and record the response/error. Then, if it fails, repeat after selecting Public distribution on the throwaway app only (permanent for that app, so use a disposable one), and check whether it works while the app is still in Draft/unlisted.

## Route (provisional)
Billing API (`appSubscriptionCreate`, `test: true`) for the single $19.99/month plan with 14-day trial, per brief default, because managed pricing ties plans to the listing. Caveat: docs call it legacy for new public apps and recommend managed pricing; revisit at review time.
