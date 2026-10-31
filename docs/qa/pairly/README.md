# Pairly QA, 2026-10-31 (in progress)

Environment: Cloudways application "Pairly BOGO" on the PROMAX server, https://phpstack-1216096-6760392.cloudwaysapps.com. App versions 1.0.0-qa, then 1.0.1-qa after review fixes (message strings changed; dev-store runs above used 1.0.0-qa). Store: levoilest.myshopify.com (development store). No real store touched.

| Check | Expected | Observed | Evidence | Result |
|---|---|---|---|---|
| Site over HTTPS | landing 200, /up 200, unsigned webhook 401 | 200, 200, 401 | curl | OK |
| Install on dev store | consent lists products and discounts, opens embedded | as expected, onboarding shows 0 of 5 | onboarding.jpg | OK |
| Save with tag FallOffer, offer on | green banner, state Running, discount created | "Saved", "Running", "Pairly: BOGO Offers" Active in Discounts | settings-saved.jpg | OK |
| Test page A, B, C at 30% | customer 1830, company 1970 | 1830 and 1970, gifts and candidates as designed | test-page-abc.jpg | OK |
| Analytics without read_orders | explains, offers button | as expected | - | OK |
| Grant read_orders | consent, then numbers | consent shown; numbers appeared only after Protected customer data reasons were saved | analytics.jpg | OK, see bug 2 |
| Analytics numbers | total discount 5,200 from earlier QA orders | EGP 5,200.00 on 4 orders | analytics.jpg | OK |
| Test subscription before Public | unknown | Shopify: "Apps without a public distribution cannot use the Billing API" | server call | Blocker for charge test |

## Bugs found live
1. Relative `SHOPIFY_SHARED_CONSTANTS_PATH` broke currency lookup on the server (Test page and analytics). Fixed in bf892f9.
2. When Protected customer data isn't declared, Shopify's denial is shown as "Allow access to orders" even after the merchant granted it. The page loops on the button. Not fixed; a reinstalled public app won't hit it after declaration.
3. Test page shifts down when settings load, so a click right after load can miss.
4. Theme extension was rejected on deploy for a tests directory. Fixed in 89a8878.

## Not verified
- Real checkout at either method: the dev store has a visitor password, which the owner must enter, and the old offer-app discount "Buy One Get One" is still active on it. Deactivating it was refused by the permission system, so the owner must do it.
- Theme embed, badge, cart message, Lighthouse, the `$app:` Liquid metafield read: all need the storefront.
- Billing charge, trial, cancel webhook: needs Public distribution.
- Arabic admin and storefront in a live session. Arabic text is a draft.
- Collection rule, dates, coupon switch at checkout.
- Uninstall and reinstall, live webhooks.
- A real non-Plus store: waits for Shopify's approval.
- Database is SQLite on the server, because a MySQL password would have to be handled in plain text; the owner can switch by editing `.env`.
