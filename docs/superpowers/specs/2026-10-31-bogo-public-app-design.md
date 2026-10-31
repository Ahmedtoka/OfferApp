# BOGO public Shopify app and website: design

**Date:** 2026-10-31
**Owner:** Ahmed (Arena)
**Status:** approved by the owner 2026-10-31
**Base:** this repo, `main`. Read first: `docs/superpowers/specs/2026-10-30-bogo-offer-app-design.md` (§4 rule, §4.4 acceptance table, change log), `docs/qa/FINAL.md`, `docs/qa/EXPLAINED.md`, `docs/security/2026-10-30-review.md`, `docs/reviews/`, `docs/deploy.md`.

Marked **[owner]** = the owner decided. **[Claude's call]** = decided by the designer because the owner delegated it or did not address it; correct it if wrong. Unmarked items restate the owner's brief.

---

## 1. Intent

Turn the Le Voile offer app into a public app on the Shopify App Store, usable by any store on any plan, with a marketing site and documentation.

**Why:** Shopify lets custom apps with Functions run only on Plus stores. Fashion Avenue (Grow) failed live with `Shop must be on a Shopify Plus plan to activate functions from a custom app`. Public App Store apps run on all plans. Development stores are exempt, so a dev store alone never proves the plan restriction is gone.

**Who it is for [owner]:** a merchant who wants to run a "crazy offer". My reading **[Claude's call]**: a strong, time-limited promotion aimed at sales volume and clearing stock.

**What makes a merchant pay [owner]:** complete analytics on the offer, and the choice of whether the calculation favours the customer or the company, with everything easy to use. "Very advanced, and above all easy."

**Competitive position:** the app replaces hand-built offer trees. A merchant turns on one rule that works for any number of units, finds the best split automatically, does not stack with coupons by default, and pays a flat price however many orders it discounts. The competitor (Regios Discounts) is used for ideas only: no copying of its name, text or design, and it is not named in public material unless the owner asks.

### Success
- Approved and listed on the Shopify App Store.
- A non-Plus store on a real paid plan installs it and gets the discount at checkout.
- A merchant goes from install to a live offer in a few minutes with no theme code edits.

---

## 2. Goals, non-goals, anti-goals

### Goals (version 1)
- G1. Any store can install: no shop allow-list in production.
- G2. Offer engine with two calculation methods (§4).
- G3. Offer settings (§5): percent, eligibility by tag or collection, on/off, start and end dates, combine with coupons or not, calculation method.
- G4. Analytics page, layout A (§6).
- G5. Test page (§7).
- G6. Storefront display through a Theme App Extension (§8), never theme code edits.
- G7. Billing through Shopify (§9).
- G8. English and Arabic (§10).
- G9. Onboarding steps on first open (§11).
- G10. Marketing site and docs (§12).
- G11. Every App Store requirement met, each with recorded evidence (§13).

### Non-goals (version 1)
- More than one offer per store **[Claude's call; owner delegated]**. Multiple offers, drafts and campaign history come later. The off state doubles as a simple draft.
- Other offer types (buy 2 get 1, buy X get Y, tiered). Considered only after version 1.
- Campaign result emails.
- Installing on any real store, including Le Voile and Fashion Avenue. That needs the owner's explicit word later (§15).
- Migrating data from `offer-app` or `BOGO Fashion Avenue`. Those stay as they are and are not redeployed from this work.
- POS, draft orders, subscriptions.
- Estimating sales lift ("the offer raised sales X%"). Only computed facts are shown.

### Anti-goals
- In "best for customer" mode, any valid split that would charge the customer less than the one chosen.
- In "best for company" mode, any valid split that would give a smaller discount than the one chosen.
- Raising a price, ever.
- A page reporting success when Shopify did not accept the change.
- A sentence on the analytics page that claims more than the numbers show.
- A merchant charged outside Shopify, or charged after cancelling.
- A secret committed: the repo is public.
- Any real store changed without the owner's explicit word.

---

## 3. Constraints

- Existing stack stays: JS Function compiled to Wasm (API 2026-07, `cart.lines.discounts.generate.run`), Laravel 12 with session tokens and token exchange, hand-written JS with Polaris web components. No SPA rewrite.
- A new app in the Dev Dashboard with its own config file (`shopify.app.bogo.toml`) and client id. `offer-app` and `BOGO Fashion Avenue` stay untouched.
- The final "Public distribution" selection is irreversible: only with the owner's explicit approval at that moment.
- All links derive from `APP_URL` and `SITE_URL`.
- Hosting **[owner, 2026-10-31, overriding the earlier separate-server brief]:** a new, separate Cloudways application on the existing PROMAX server, from this same repo. Existing applications on that server, including "BOGO Offer Fashion Avenue", are not changed. Hybrid stack, web root `web/public`, Varnish off, HTTPS on. Its own database and `.env`. Dev store testing comes first; the Cloudways temporary URL is used until the owner buys the domain.
- No passwords typed or asked for in chat. The owner does every sign-in.
- Small commits. TDD throughout. Any engine change must pass §4.4 and the brute-force tests.

---

## 4. The engine [owner]

The existing rule stays: every two units give one gift (the gift is never more expensive than its partner), an odd unit left over gets `pct`% off its full price, no unit limit, and a price never rises. Eligibility, `P_now`, `P_full` and money rules are as in the 2026-10-30 spec §4.

The merchant picks a method:

### `customer` (default [Claude's call])
Exactly today's behaviour: the largest possible discount. Must keep passing §4.4 and the brute-force test.

### `company`
The smallest possible discount that still honours the offer: each gift is paired with a distinct paid unit at the same or higher `P_now`, every pair gives a gift, and an odd cart has one single unit at `pct`%.

- Even count: the gifts are the cheapest half of the units.
- Odd count: try each unit as the single, gifts = the cheapest half of the rest, keep the smallest total discount. Ties resolved deterministically.

Acceptance (`pct` = 30, prices from §4.4):

| Units | Gifts | 30% on | Customer pays | `customer` mode pays |
|---|---|---|---|---|
| A, B, C, D | D, C | none | 2300 | 2100 |
| A, B, C | C | B → 770 | 1970 | 1830 |

Note that today's "scenario 2" (only the odd single differs; even carts identical) is **replaced** by this definition everywhere.

### Requirements
- Implemented in the JS engine and the PHP `BogoCalculator`, with shared-case parity tests.
- A new brute-force test proves `company` is minimal, as the existing one proves `customer` is maximal.
- Per-line planning stays independent of unit quantity; instruction counts recorded for fixtures up to 199 units in both modes.
- Function configuration carries the method. Missing or invalid value fails safe to `customer` for configs saved before this field existed, while other malformed config continues to mean no discount.
- Multi-currency: amounts are in the cart's presentment currency. Rounding must respect each currency's decimals, including zero- and three-decimal currencies, replacing the two-decimal assumption in `money.js` and `toPiastres`. Tested with non-EGP carts.

---

## 5. Settings page

One offer per store. Fields: on/off, percent (1–99, default 30), applies to (tag or collection, one choice), start date and optional end date, combine with coupons (off by default), calculation method (default `customer`).

- Tag default is empty in the public app: `FallOffer` is Le Voile's, not a universal default. The merchant must choose.
- Collection eligibility uses the same input-variables mechanism as the tag.
- Dates map to the discount's `startsAt` and `endsAt`. The status line must tell the truth about combined state: Off, Scheduled, Running, Ended. This fixes the current "active in Shopify" text shown while the offer is off.
- Coupon switch maps to `combinesWith`. The exact mapping (product, order and shipping classes) and what a shopper sees are verified on a dev store and written down.
- Save reports success only after Shopify's values read back correct. Make the save order safe so a failed step cannot leave the page and the Function disagreeing about the tag or collection (the current metafield-first order can).
- Saving must not silently reactivate a discount the merchant deliberately deactivated in Shopify while the offer is being saved as off.

---

## 6. Analytics: layout A [owner]

Owner chose layout A from the mockup: **one sentence stating the result, four numbers, details collapsed until opened.**

- **Sentence:** describes numbers only, for example "Offer orders average 68% more than other orders and the offer cost 22% of its sales." It says the offer "won" only if profit can actually be computed from unit costs **[Claude's call]**.
- **Four numbers:** offer sales (with order count), total discount (with % of sales), average order value (compared with non-offer orders), units per order (compared with non-offer orders).
- **Collapsed details [Claude's call, offered to the owner, no objection]:**
  - Gifts and products: gift units, percent units, top 10 gifted products, share of all orders.
  - The other method: on the same orders, what the method not currently selected would have changed, in money.
  - Approximate profit: only for products with a cost in Shopify; clearly labelled as partial coverage.
- Ranges 7, 30, 60 days. Cached with the cache age visible, so ranges cannot silently disagree (QA saw this). Order cap and note retained.
- Amounts in the shop's currency, not hard-coded EGP.
- Fix before relying on it: analytics titles classification must recognise every title and language the Function can emit (§10). Refund handling is stated on the page.

### Permissions
`read_orders` is an optional scope requested from a separate button. Without it the page explains what it unlocks and everything else works. Any extra scope for unit costs is optional too and asked for separately. Without Shopify's approval for all orders, only the last 60 days are available; the page says so.

---

## 7. Test page

The merchant builds a sample cart, from real products or typed prices, and sees the result without touching checkout: what each unit pays, and the candidate splits with the chosen one marked, like `docs/qa/EXPLAINED.md`. It shows both methods side by side so the merchant sees the difference before switching on.

Uses the PHP engine (kept honest by the parity tests) and the store's currently saved percent and method. It states which products in the sample are not eligible and why.

---

## 8. Storefront: Theme App Extension

App embed and blocks only. Never edit theme code or supply a modified theme.

- Product badge on eligible products.
- Cart message guiding the next step, like "Add one more and get it free".
- Merchant-editable text with English and Arabic defaults; deep link from the app into the theme editor to enable the embed.
- Must not lower Lighthouse by more than 10 points (Shopify's weighted home, product and collection measure). Measured and recorded before submission. No app promotion or review requests on the storefront.
- Showing the discounted price on the product page is not in version 1 **[Claude's call]**: the discount depends on the whole cart, so a single price can mislead.

---

## 9. Billing [owner]

- **One plan: $19.99 per month, flat, unlimited orders, 14-day free trial.** Development stores free.
- Billing only through Shopify. Implementation route (Billing API or Shopify's managed pricing) chosen at planning against the current docs.
- Offer cannot be turned on without an active subscription or trial. On cancellation or failed billing the offer is turned off and the merchant is told why.
- Charges tested on a dev store with test charges, and confirmed in the charge history.
- The price was approved before a current market check; a quick comparison is done before the listing, and any finding comes back to the owner rather than changing the price silently.

---

## 10. Languages

English primary, Arabic with full right-to-left layout, in the admin, storefront defaults and customer-facing discount messages. The admin follows the merchant's Shopify language **[Claude's call]**. Function messages follow the buyer's language when available; analytics must classify all of them, including the legacy Arabic titles it already knows.

---

## 11. Onboarding

Short steps on first open with visible progress: choose products (tag or collection), set percent and method, try the Test page, enable the theme embed, turn the offer on. Installs always start with the offer off.

---

## 12. Website and docs

Served from this repo under `SITE_URL` as public Laravel routes **[Claude's call]**, English and Arabic **[Claude's call]**.

- **Landing:** problem, solution, a fair comparison with the manual approach (no competitor named), pricing, FAQ, install button.
- **Docs:** setup, tag or collection, Test page, theme, pricing, FAQ.
- **Legal:** privacy policy and terms of use. Text drafted here; the owner is responsible for final legal acceptance and for the business identity shown.

**Look [owner, 2026-10-31]:** a 3D website with moving screens showing the product and strong advertising lines, plus a simple logo and an app icon made here. Other design choices are the builder's. It must look professional, fit phone width, stay fast, and honour reduced-motion settings **[Claude's call]**. Advertising lines must avoid outcome guarantees.

---

## 13. App Store requirements

Follow https://shopify.dev/docs/apps/launch/app-requirements-checklist in full, recording evidence per item in `docs/qa/`. Most relevant:

- **Name:** the checklist says lead with your own brand, not a generic word, be distinctive, 30 characters or fewer, and match the TOML. "BOGO" is generic and close to the existing app "BOGOS", so it is likely to be rejected. **Final name: open, see §16.**
- Embedded with App Bridge, session tokens, works in Chrome incognito, no third-party cookies.
- `SHOPIFY_ALLOWED_SHOPS` optional and empty in production. All tests that assume it updated.
- Mandatory privacy webhooks; `app/uninstalled` removes the token; `shop/redact` deletes the shop's row.
- Protected customer data: Level 1 details completed, order use justified, no customer fields read.
- Least-privilege scopes, extras optional.
- Listing assets per checklist: 1200×1200 icon, 3–6 screenshots at 1600×900, feature media, intro under 100 characters, details under 500, privacy policy, support email, reviewer instructions with screencast in English, prepared dev store.
- Built for Shopify is a target, not a condition of delivery.

Known code to fix for a public app: hard-coded EGP, "Le Voile" in the extension name and `SHOPIFY_FUNCTION_TITLE`, two-decimal money, no recovery when a stored token is dead with no uninstall webhook, webhook hardening from the security review minor list. The npm audit acceptance is re-checked.

---

## 14. How the work runs

1. This design, approved.
2. `superpowers:writing-plans` to `docs/superpowers/plans/`.
3. `superpowers:subagent-driven-development`, each track owning its files, Code Reviewer after each.
4. `superpowers:test-driven-development` mandatory.
5. Independent `security-review` before submission.
6. Real browser QA on a dev store: screenshots and an expected/observed table in `docs/qa/`; anything untested is stated.
7. Staging, deploy, submission, then answering Shopify's review until approved.

Every released version is recorded in an updated deploy guide. Final message states what was confirmed, what was not, and what is needed from the owner.

---

## 15. Owner-only steps

Stops where work waits for the owner:

- Sign-ins: CLI, Dev Dashboard, Cloudways, partners.
- Create or pay for the separate Cloudways server, domain, any registration fee.
- Explicit approval at the Public distribution step.
- Review of legal text and business identity.
- After approval: install on Le Voile and Fashion Avenue, starting with the offer off.

Cleanup from the last phase, performed by or with explicit approval of the owner:
- Remove SSH key `offerapp-deploy` from the PROMAX server and change the master password.
- Decide whether Cloudways app "BOGO Offer Fashion Avenue" stays as staging or is deleted.
- Remove `BOGO Fashion Avenue` from the Fashion Avenue store once the public app is approved.

---

## 16. Open before approval

Resolved by the owner on 2026-10-31, accepting the designer's proposals:

1. **App name [owner]:** **Pairly: BOGO Offers**. Availability is unverified; if Shopify rejects it, fall back to the alternatives TwoFer: BOGO Deals or GiftPair: BOGO Discounts and tell the owner. Kept in one config value.
2. **Domain timing [owner]:** the domain is bought before submission, not after approval. Until then, staging uses the Cloudways temporary URL.
3. **Non-Plus proof [owner]:** Fashion Avenue, after Shopify's approval, only when the owner says so at that moment, starting with the offer off.
4. **Le Voile and Fashion Avenue billing [owner]:** they pay like any merchant. No exemption mechanism is built.

The owner asked for the work to be split across agents and to move as fast as possible. Shopify's review time is outside this project's control. This does not widen any approval rule in §3 or §15.

## 16a. Sequencing decisions [Claude's call]

- **Billing before distribution:** the plan starts with a short spike on a throwaway app to learn whether billing and the subscription gate can run end to end before Public distribution is selected. If they cannot, work stops and the owner decides whether to select it early; it is never selected without the owner's explicit approval.
- **Staging:** local work uses the Shopify CLI tunnel on a dev store, then the new Cloudways application on PROMAX (§3). The "BOGO Offer Fashion Avenue" application is left alone.

## 17. Left to the builder

Internal structure, how `SITE_URL` routes coexist with the embedded app, i18n mechanism, Function config shape, theme extension internals, Billing API versus managed pricing, how a cart is rendered on the Test page, chart-free or chart details within layout A, and test tooling.
