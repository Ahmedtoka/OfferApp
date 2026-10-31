# Pairly: BOGO Offers, instructions for Shopify's app reviewer

English. Paste into the submission form's testing instructions. Items marked (owner) need a value only the owner has before submission.

## Before you start
- App: Pairly: BOGO Offers. Admin page URL is opened from Apps in the Shopify admin after install.
- Test store: (owner) the prepared development store URL. Storefront password, if the store is password protected: (owner, entered in the form, never in this repository).
- No third-party accounts or credentials are needed. Pairly stores no customer data and reads no customer fields.
- A screencast of the full flow below, in English: (owner or Claude to record, **not yet made**).
- Support email: (owner).

## Setup
1. Install the app. Approve the scopes: it asks for `write_discounts` and `read_products`. `read_orders` and `read_inventory` are optional and requested later from buttons in the analytics page.
2. The home page shows five steps: choose products, set percent and method, try the Test page, enable the theme embed, turn the offer on. The offer is off after install.
3. Settings: set applies to = tag (for example `FallOffer`; the tag must exist on at least two products) or a collection, percent 30, method "Best for customer". Leave coupons off. Do not turn it on yet.
4. Test page: add three pieces priced 1200 (A), 1100 (B) and 900 (C), typed prices or real products. Press Run test.
5. Theme: Online Store, Customize, App embeds, enable "Pairly". Add the "Pairly product badge" block on the product template.
6. Settings: turn the offer on and save. If the store has no active plan you are sent to Shopify's approval page for the $19.99 per month plan (14-day trial). Approve it; the charge is a test charge on a development store.
7. On the storefront add two eligible products to the cart and open checkout.

## Test cases
| # | Action | Expected |
|---|---|---|
| 1 | Install, open the app | Embedded page loads with five steps, 0 of 5 done, offer off |
| 2 | Save settings with the offer off | Message "Saved", status Off. Nothing reaches shoppers |
| 3 | Test page, pieces 1200, 1100, 900 at 30 percent | Best for the customer: B free, C at 30 percent off, shopper pays 1830. Best for the store: C free, B at 30 percent off, shopper pays 1970. Both shown side by side with the chosen split marked |
| 4 | Turn the offer on with an active plan | Status Running; an automatic discount named "Pairly: BOGO Offers" is Active under Discounts |
| 5 | Checkout with two eligible units | One unit is free, labelled by the discount message. A third eligible unit gets 30 percent off |
| 6 | Checkout with one eligible and one ineligible product | No discount |
| 7 | Add a coupon code at checkout while "combine with coupons" is off | Either the coupon or the offer applies, not both |
| 8 | Analytics page without `read_orders` | Explains what is unlocked, shows a button. The rest of the app works |
| 9 | Press the button and approve | Shopify consent, then order numbers appear for the chosen range |
| 10 | Switch the admin language to Arabic | Layout is right to left and text is Arabic |
| 11 | Uninstall | Access token is erased at once. A later shop/redact webhook deletes the shop's row |

Cases 5 to 7 and the storefront parts of 4 and 10 depend on a storefront the owner prepares; they have **not** been run live yet (see `docs/qa/pairly/README.md`).

## How billing behaves on a development store
- One plan: $19.99 per month, flat, unlimited orders, 14-day free trial. Billing goes only through Shopify (Billing API `appSubscriptionCreate`).
- On a development store the app creates the subscription with `test: true`, so nothing is charged. The charge appears in the app's charge history in the admin.
- The offer cannot be turned on without an active subscription or trial. If the subscription is cancelled or declined, the app turns the offer off and tells the merchant.
- Approval opens in the same window (a full-page navigation, no pop-up). Upgrading and downgrading do not apply: there is one plan.
- Billing has not yet been exercised live: Shopify rejects subscription calls from apps without Public distribution ("Apps without a public distribution cannot use the Billing API"), so the first live charge test follows the owner's choice of Public distribution.

## Notes for the reviewer
- The app never edits theme code. Storefront output is a theme app extension (app embed and product badge block). The storefront shows no review requests and no promotion of other apps.
- Customer-facing text defaults to English and Arabic.
- Support: (owner email), docs at (SITE_URL)/docs.
