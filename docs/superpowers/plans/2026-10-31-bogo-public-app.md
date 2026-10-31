# Pairly public app and website Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Le Voile BOGO app into a multi-store public Shopify app named "Pairly: BOGO Offers" with a website, ready for App Store submission.

**Architecture:** Same repo and stack. The JS Function stays the source of truth at checkout, gaining a `company` method, per-currency rounding, collection eligibility and localized messages. Laravel gains billing, a test endpoint, richer analytics and public site routes. The embedded page stays hand-written JS with Polaris web components. A new Theme App Extension covers the storefront.

**Tech Stack:** JS to Wasm Discount Function (API 2026-07), Vitest, Laravel 12, PHPUnit, Polaris web components, App Bridge, Liquid theme app extension, Shopify CLI 4.9.4.

**Spec:** `docs/superpowers/specs/2026-10-31-bogo-public-app-design.md`

## Global Constraints

- Work on branch `feat/pairly-public-app`. Never push or merge without the owner's word. Small commits.
- TDD on all behaviour. Any engine change must pass the 2026-10-30 §4.4 table and brute-force tests. `cd web && php artisan test`, `cd extensions/bogo-discount && npx vitest run` green at the end of each task.
- `offer-app` and `BOGO Fashion Avenue` configs are not edited or redeployed. `shopify.app.toml` and `shopify.app.fashion-avenue.toml` stay byte-identical.
- App name `Pairly: BOGO Offers`, 30 characters or fewer, kept in one config value.
- Never: select Public distribution, install on a real store, delete data on a server or store, pay, or type a password, without the owner's explicit word at that moment.
- No secrets committed: the repo is public.
- Installs start with the offer off. Scopes: `write_discounts`, `read_products` required. `read_orders` and unit-cost access optional.
- Price: one plan, $19.99 per month, 14-day trial, dev stores free.
- Every merchant-visible string exists in English and Arabic. Function config: missing `method` means `customer`; any other malformed config means no discount.
- All absolute links derive from `APP_URL` or `SITE_URL`.

## Review Focus

- Merchant changes tag to collection (or back) while the offer is on: the Function must use only the new rule after Save, and a failed Save must leave both sides on the old rule. Pinned in Task 7.
- Subscription cancelled or trial ended while the offer is on: offer turns off and the page says why. Pinned in Task 8.
- Stored token dead (app removed with no webhook delivered): next visit reinstalls by token exchange instead of failing forever. Pinned in Task 6.
- Cart in a zero- or three-decimal currency: no fractional amount invalid for the currency, gift never exceeds the line price. Pinned in Task 2.
- Orders with Arabic or English messages, and `customer` or `company` methods, in one analytics range: all classified, other-method figure never negative. Pinned in Task 9.

## File ownership by track

| Track | Tasks | Owns |
|---|---|---|
| Engine | 1–4 | `extensions/bogo-discount/**`, `shared/**`, `web/app/Offer/**` |
| Backend | 5–10 | `web/app/**` except Offer, `web/routes/api.php`, `web/config/**`, `web/database/**`, `web/.env.example`, tests |
| Admin UI | 11–12 | `web/resources/views/home.blade.php`, `web/public/app.js`, `web/public/app.css`, `web/lang/**` |
| Theme | 13 | `extensions/pairly-theme/**` |
| Site | 14 | `web/resources/views/site/**`, `web/routes/web.php`, `web/public/site.css`, `web/app/Http/Controllers/SiteController.php` |
| Release | 15–17 | `shopify.app.bogo.toml`, `docs/**` |

Order: Task 0, then Engine, then Backend, then UI, Theme and Site in parallel, then Release.

---

### Task 0: Billing and distribution spike (throwaway)

**Files:** Create `docs/spike/2026-10-31-billing-distribution.md`. No product code.

- [ ] **Step 1:** Read Shopify's current billing docs and record whether an app with no distribution selected can complete a test subscription on a dev store, by Billing API (`appSubscriptionCreate` with `test: true`) and by managed pricing.
- [ ] **Step 2:** If docs do not settle it, try `appSubscriptionCreate` with `test: true` on `levoilest.myshopify.com` through a throwaway dev app. Stop and ask the owner if this needs a new sign-in.
- [ ] **Step 3:** Record the route chosen. Default: Billing API, because it does not depend on listing setup. If billing cannot be tested before Public distribution, stop and report to the owner.
- [ ] **Step 4:** Commit: `docs(spike): billing before distribution`.

---

### Task 1: `company` method in the JS engine

**Files:** Modify `extensions/bogo-discount/src/bogo.js`; Test `extensions/bogo-discount/tests/bogo.test.js`, `tests/bogo.bruteforce.test.js`; Modify `shared/fixtures/bogo-cases.json`, `shared/offer-constants.json`.

**Interfaces:**
- Produces: `planLineDiscounts(lines, pct, method = 'customer') -> [{lineId, giftQty, giftAmount, singleQty, singleAmount}]`; `computeOffer(units, pct, method = 'customer')` where method is `'customer' | 'company'`, replacing today's `'max' | 'min'`. Shared constant `methods: ["customer","company"]`, `defaultMethod: "customer"`.

- [ ] **Step 1: Write failing tests**, with §4.4 prices A=1200, B=1100, C=900, D=800 (in minor units):

```js
expect(pay(['A','B','C','D'], 30, 'company')).toBe(230000); // gifts D, C
expect(pay(['A','B','C'], 30, 'company')).toBe(197000);     // gift C, B at 30%
expect(pay(['A','B','C','D'], 30, 'customer')).toBe(210000);
expect(pay(['A','B','C'], 30, 'customer')).toBe(183000);
```

Brute-force test: for random carts up to 7 units with markdowns, enumerate every valid assignment (each gift paired with a distinct paid unit whose `pNow` is greater or equal; odd carts one single). `company` total discount equals the enumerated minimum; `customer` equals the maximum. `planLineDiscounts(..., 'company')` aggregates the same as `computeOffer`.

- [ ] **Step 2:** Run `npx vitest run tests/bogo.test.js tests/bogo.bruteforce.test.js`. Expected: FAIL.
- [ ] **Step 3: Implement.** Even: gifts are sorted indices `0..n/2-1`. Odd: for candidate single at index i, gifts are the first `(n-1)/2` of the sorted remainder, so with prefix sums: gift total = `prefix((n-1)/2)` if `i >= (n-1)/2`, else `prefix((n+1)/2) - pNow[i]`. Keep the smallest saving; ties by smaller `pFull`, then smaller index. `planLineDiscounts` keeps working on runs so cost does not grow with quantity.
- [ ] **Step 4:** Add fixtures `company_abcd`, `company_abc`, `company_bulk_199`. Run the suite: all pass, instruction counts recorded in `docs/qa/test-output/function-run.txt` below the limit.
- [ ] **Step 5:** Commit: `feat(engine): company calculation method`.

### Task 2: Per-currency rounding

**Files:** Modify `extensions/bogo-discount/src/money.js`, `src/cart_lines_discounts_generate_run.js`, `src/cart_lines_discounts_generate_run.graphql`, `web/app/Offer/BogoCalculator.php`; Create `shared/currency-decimals.json`; Test `tests/run.test.js`, `web/tests/Unit/Offer/BogoCalculatorTest.php`.

**Interfaces:**
- Produces: `decimalsFor(currencyCode) -> int`; `toMinor(value, decimals) -> int`; `toAmountString(minor, decimals) -> string`. PHP `BogoCalculator::toMinor(string|float|int $amount, int $decimals): int`, `BogoCalculator::decimalsFor(string $currency): int`.

- [ ] **Step 1: Write failing tests:** `toMinor('12.345', 3) === 12345`; `toAmountString(12345, 3) === '12.345'`; `toMinor('1500', 0) === 1500`; `toAmountString(1500, 0) === '1500'`; `decimalsFor('KWD') === 3`, `('JPY') === 0`, `('EGP') === 2`, unknown code 2. Run-level: KWD single line 1.235 at 30% gives valid three-decimal amount; JPY gift equals the whole line price. Existing `toPiastres` callers keep passing through `toMinor(value, 2)`.
- [ ] **Step 2:** Run tests. Expected: FAIL.
- [ ] **Step 3: Implement.** Add `cart { cost { subtotalAmount { currencyCode } } }` to the input query after confirming the field in `schema.graphql` (`MoneyV2.currencyCode` exists at line 5180). `shared/currency-decimals.json` lists zero-decimal and three-decimal ISO codes per ISO 4217.
- [ ] **Step 4:** Run both suites. Expected: PASS.
- [ ] **Step 5:** Commit: `fix(engine): round to each currency's decimals`.

### Task 3: Function config, collection eligibility, localized messages

**Files:** Modify `src/cart_lines_discounts_generate_run.js`, `.graphql`, `shopify.extension.toml`, `tests/run.test.js`, `tests/input-query.test.js`, `tests/generate-fixtures.mjs`, `shared/offer-constants.json`.

**Interfaces:**
- Consumes: Task 1 `planLineDiscounts`, Task 2 money.
- Produces: discount metafield `$app:bogo/config` = `{"eligibleTags": string[], "collectionIds": string[]}`; shop metafield `$app:bogo/config` = `{enabled, percent, method, appliesTo: "tag"|"collection", eligibleTag, collectionId, updated_at}`. Constants `giftMessages: {en:"Gift", ar:"هدية"}`, `percentMessageTemplates: {en:"{pct}% off", ar:"خصم {pct}%"}`. Extension name read from config value `Pairly Discount`.

- [ ] **Step 1: Write failing tests:** line passes when `hasAnyTag` or `inAnyCollection` is true and fails when both false; `method` absent runs `customer`; `method: 'company'` runs company; `method: 'bad'` returns no operations; language `AR` gives `هدية` and `خصم 30%`; other languages give English.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3: Implement.** Query adds `$collectionIds: [ID!]!`, `inAnyCollection(ids: $collectionIds)`, `localization { language { isoCode } }`. Empty arrays match nothing, so the unused rule stays inert. Keep legacy shop configs working: no `method` means customer.
- [ ] **Step 4:** Regenerate fixtures, run the suite and a Function build. Expected: PASS, "Function built successfully."
- [ ] **Step 5:** Commit: `feat(function): collection eligibility, method config, localized messages`.

### Task 4: PHP engine parity

**Files:** Modify `web/app/Offer/BogoCalculator.php`, `web/tests/Unit/Offer/BogoCalculatorTest.php`.

**Interfaces:**
- Produces: `BogoCalculator::compute(array $units, int $pct, string $method = 'customer'): OfferResult`; `BogoCalculator::candidates(array $units, int $pct, string $method): array` returning `list<array{single: ?Unit, gifts: list<Unit>, totalDiscount: int, totalPay: int, chosen: bool}>`, for the Test page.

- [ ] **Step 1: Write failing test:** every case in `shared/fixtures/bogo-cases.json`, both methods, matches totals expected there; `candidates` marks exactly one chosen, equal to `compute`.
- [ ] **Step 2:** Run `php artisan test --filter=BogoCalculatorTest`. Expected: FAIL.
- [ ] **Step 3:** Implement. `'max'`/`'min'` callers move to the new names in Task 9.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(offer): PHP parity for both methods`.

---

### Task 5: Open installs and per-app configuration

**Files:** Modify `web/config/shopify.php`, `web/app/Http/Middleware/VerifyAdminQueryHmac.php`, `web/app/Http/Middleware/VerifySessionToken.php`, `web/app/Shopify/SessionToken.php`, `web/app/Models/Shop.php`, `web/.env.example`, `web/tests/Support/SessionTokens.php`, related tests.

**Interfaces:**
- Produces: `Shop::isAllowed(string $domain): bool` true for any valid `*.myshopify.com` when `shopify.allowed_shops` is empty; config `shopify.app_name`, `shopify.site_url` (`SITE_URL`), `shopify.function_title` default `Pairly Discount`.

- [ ] **Step 1: Write failing tests:** empty allow-list accepts any valid domain and still rejects `x.myshopify.com.evil.com`; non-empty list still restricts as today.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. `SessionToken::verify` treats an empty list as open while keeping `normalizeDomain`. An empty `api_secret` still rejects.
- [ ] **Step 4:** Run whole PHP suite. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(auth): allow any store when no allow-list is set`.

### Task 6: Token recovery, uninstall and redact cleanup

**Files:** Modify `web/app/Shopify/HttpGraphqlClient.php`, `web/app/Http/Middleware/VerifySessionToken.php`, `web/app/Http/Controllers/WebhookController.php`; Create `web/app/Shopify/TokenRevoked.php`; Test `web/tests/Feature/AuthMiddlewareTest.php`, `WebhookTest.php`.

**Interfaces:**
- Produces: `HttpGraphqlClient::query` throws `TokenRevoked` (extends `ShopifyGraphqlException`) on HTTP 401; `Shop::markTokenRevoked(string $domain)` clears the token so `Shop::active` is null and the next session-token request exchanges again.

- [ ] **Step 1: Write failing tests:** 401 from Shopify clears the token and returns 401 to the page; next request exchanges and succeeds. `shop/redact` deletes the row; `customers/*` still log only. `app/uninstalled` with body `myshopify_domain` differing from the header changes nothing.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Scopes column refreshes on each exchange.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit: `fix(auth): recover from a revoked token; redact deletes the shop`.

### Task 7: Settings: new fields and safe save

**Files:** Modify `web/app/Services/OfferSettings.php`, `web/app/Services/AutomaticDiscountManager.php`, `web/app/Http/Requests/SaveSettingsRequest.php`, `web/app/Http/Controllers/SettingsController.php`, `web/tests/Feature/SettingsTest.php`; Create `web/app/Services/OfferState.php`.

**Interfaces:**
- Consumes: Task 3 metafield shapes.
- Produces: `PUT /api/settings` body `{enabled: bool, percent: int, method: 'customer'|'company', appliesTo: 'tag'|'collection', eligibleTag: ?string, collectionId: ?string, startsAt: ISO8601, endsAt: ?ISO8601, combineWithCoupons: bool}`. `GET /api/settings` also returns `state: 'off'|'scheduled'|'running'|'ended'` from `OfferState::from(array $settings, array $discount, CarbonInterface $now): string`.

- [ ] **Step 1: Write failing tests:**
  - Validation: `eligibleTag` required when `appliesTo` is tag (no default), valid `gid://shopify/Collection/...` when collection; `endsAt` after `startsAt`.
  - Save order: if the discount step fails, the shop metafield still holds the old values, response 422.
  - Switching tag to collection writes `{"eligibleTags":[],"collectionIds":["gid"]}` and reads it back.
  - `enabled:false` Save does not call activate on a deactivated discount. `enabled:true` does.
  - `combineWithCoupons:true` writes `combinesWith` product and order true, shipping true; false writes all false.
  - `OfferState` for each of off, scheduled, running, ended.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement: `ensure()` runs first (discount and its metafield, verified), then shop metafield, then read back. Discount dates set by `discountAutomaticAppUpdate`. Function lookup uses `shopify.function_title`.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(settings): method, collection, dates, coupons, safe save order`.

### Task 8: Billing

**Files:** Create `web/app/Services/Billing.php`, `web/app/Http/Controllers/BillingController.php`; Modify `web/routes/api.php`, `web/routes/web.php`, `web/app/Http/Controllers/WebhookController.php`, `web/app/Services/OfferSettings.php`, `shopify.app.bogo.toml` (Task 15 creates it; add topic there); Test `web/tests/Feature/BillingTest.php`.

**Interfaces:**
- Consumes: Task 0 route decision (below assumes Billing API).
- Produces: `Billing::status(string $shop): array{active: bool, trialDaysLeft: ?int, partnerDevelopment: bool}`; `Billing::subscribe(string $shop): string` confirmation URL; `GET /api/billing`, `POST /api/billing/subscribe`; webhook `app_subscriptions/update`. Constants `planName: "Pairly"`, `priceUsd: 19.99`, `trialDays: 14`.

- [ ] **Step 1: Write failing tests:** subscribe sends `19.99 USD`, `EVERY_30_DAYS`, `trialDays:14`, `test:true` only when `APP_ENV` is not production or the store is a partner dev store; `enabled:true` Save with no subscription returns 402 with a clear message; dev store passes; `enabled:false` always allowed; webhook status `CANCELLED`, `EXPIRED` or `FROZEN` writes `enabled:false` to the shop metafield, and the page reports "off because billing ended".
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Dev store detection by `shop { plan { partnerDevelopment } }`.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(billing): one plan with trial, gate on turning on`.

### Task 9: Analytics rebuild

**Files:** Modify `web/app/Services/Analytics/OfferAnalytics.php`, `AnalyticsResult.php`, `OrdersFetcher.php`, `web/app/Http/Controllers/AnalyticsController.php`, `web/tests/Feature/AnalyticsTest.php`, fixtures.

**Interfaces:**
- Produces: `GET /api/analytics` returns `{currency, rangeDays, ordersTotal, offerOrders, offerOrdersPct, offerSales, totalDiscount, discountPctOfSales, aovOffer, aovOther, unitsOffer, unitsOther, giftUnits, percentUnits, otherMethod: {method, difference}, topGifts, profit: ?{amount, coveredPct}, capped, computedAt}`; 403 `{error:'needs_read_orders'}` when the scope is missing.

- [ ] **Step 1: Write failing tests:**
  - `aovOffer`, `aovOther`, `unitsOffer`, `unitsOther`, `discountPctOfSales` from a mixed fixture; `aovOther` is null with no non-offer orders.
  - Currency from `shop { currencyCode }`, amounts rounded by Task 2 decimals.
  - Titles `Gift`, `هدية`, `30% off`, `خصم 30%`, `Pairly: BOGO Offers` classified; manual "Gift" ignored.
  - `otherMethod`: selected `customer` gives what company would have charged extra on the same orders; selected `company` gives what the customer would have saved; never negative. Even cart now contributes (new definition).
  - Missing `read_orders` returns the 403; `profit` null without cost access, with `coveredPct` when partial.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Cache key adds method and appliesTo value.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(analytics): sales comparison, both methods, shop currency, optional scope`.

### Task 10: Test endpoint

**Files:** Create `web/app/Http/Controllers/TestCartController.php`, `web/app/Http/Requests/TestCartRequest.php`; Modify `web/routes/api.php`; Test `web/tests/Feature/TestCartTest.php`.

**Interfaces:**
- Consumes: Task 4 `candidates`.
- Produces: `POST /api/test-cart` body `{lines: [{variantId?: gid, title?: string, price?: string, compareAt?: string, quantity: int}]}` returns `{customer: Result, company: Result, ineligible: [{title, reason}]}`, `Result = {totalPay, totalDiscount, units: [...], candidates: [...]}`. Candidates capped at 25 with a note.

- [ ] **Step 1: Write failing tests:** A,B,C returns customer 1830, company 1970; variant lacking the tag appears in `ineligible` and is not counted; quantity above 200 rejected; variant of another shop returns ineligible.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement with server-side product lookup using `read_products`.
- [ ] **Step 4:** Run. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(test-page): sample cart endpoint`.

---

### Task 11: Admin shell, i18n, settings and onboarding

**Files:** Modify `web/resources/views/home.blade.php`, `web/public/app.js`, `web/public/app.css`, `web/app/Http/Controllers/HomeController.php`, `web/tests/Feature/HomePageTest.php`; Create `web/lang/en/app.php`, `web/lang/ar/app.php`, `web/public/js/*.js` as needed.

**Interfaces:**
- Consumes: Tasks 7, 8 API.

- [ ] **Step 1: Write failing tests:** `locale=ar` renders `dir="rtl"` and Arabic strings; default English LTR; en and ar lang files have identical keys; page contains no `Le Voile`, `FallOffer` or `EGP`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement pages Home (onboarding checklist: products, percent and method, test, theme embed with deep link, turn on), Settings per spec §5, status line from `state`, billing banner. Navigation through `s-app-nav`.
- [ ] **Step 4:** Run, then open with `shopify app dev` on the dev store: check both languages and phone width.
- [ ] **Step 5:** Commit: `feat(admin): settings, onboarding, Arabic`.

### Task 12: Analytics layout A and Test page

**Files:** Modify `web/public/app.js`, `web/public/app.css`, `web/resources/views/home.blade.php`, `web/lang/*/app.php`.

**Interfaces:**
- Consumes: Tasks 9, 10 API.
- Produces: `summarySentence(a, t) -> string` in `web/public/js/summary.js`.

- [ ] **Step 1: Write failing tests** with Vitest at root or `node --test` in `web/tests/js/summary.test.mjs`: with AOV data, sentence includes both percentages; word "won" appears only when `profit` is present and positive; no comparison clause when `aovOther` is null.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement: sentence, 4 tiles, collapsed sections (gifts and products, other method, profit), cache age, optional-scope button by `shopify.scopes.request`. Test page: resource picker plus typed prices, both methods side by side, candidates with chosen marked.
- [ ] **Step 4:** Run tests, then check in browser on the dev store.
- [ ] **Step 5:** Commit: `feat(admin): analytics layout A and test page`.

### Task 13: Theme App Extension

**Files:** Create `extensions/pairly-theme/shopify.extension.toml`, `blocks/product-badge.liquid`, `blocks/app-embed.liquid`, `assets/pairly.js`, `assets/pairly.css`, `locales/en.default.json`, `locales/ar.json`, `tests/message.test.js`. Modify `web/app/Services/OfferSettings.php` only to expose the storefront fields in the shop metafield (coordinate after Task 7).

**Interfaces:**
- Consumes: shop metafield from Task 3, read through `app.metafields.bogo.config`; product eligibility through `product.tags` or `product.collections`.
- Produces: `nextStepMessage(eligibleUnits, pct, t) -> string|null` in `assets/pairly.js`, exported for test.

- [ ] **Step 1: Write failing tests:** 0 units null; 1 unit "Add one more and get it free" with its Arabic key; 2 units null; 3 units same as 1 unit; offer off null.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Eligible unit count in the cart from a `/cart.js` fetch plus a section-rendered eligibility list. Merchant text as block settings. No external requests, CSS and JS deferred, under 10 KB each.
- [ ] **Step 4:** Run tests. In the dev store theme editor: enable the embed, add the block, see the badge and message. Record Lighthouse before and after on home, product and collection, weighted 17/40/43; drop is 10 or less.
- [ ] **Step 5:** Commit: `feat(theme): product badge and cart message`.

### Task 14: Website, docs, legal

**Files:** Create `web/app/Http/Controllers/SiteController.php`, `web/resources/views/site/{layout,home,pricing,docs,privacy,terms}.blade.php`, `web/lang/{en,ar}/site.php`, `web/public/site.css`, `web/tests/Feature/SiteTest.php`; Modify `web/routes/web.php`.

**Interfaces:**
- Produces: public `GET /site`, `/site/ar` and subpaths, or `/` when host equals `SITE_URL` host. Embedded `/` with `hmac` or `id_token` unchanged. Install link from config.

- [ ] **Step 1: Write failing tests:** each page returns 200 without auth in both languages; `/` with no auth on the app host still returns 401; `/?hmac=...` valid still renders the app; pages contain `$19.99`, `14`, no competitor name, no `Le Voile`; privacy lists the data the app reads and deletion on `shop/redact`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement using `impeccable` or `frontend-design` for visual direction. 3D hero with animated product screens (settings, analytics, test page, cart) and strong advertising lines with no outcome guarantees; Three.js self-hosted in `web/public/vendor/`, lazy-loaded, static fallback when WebGL is missing or `prefers-reduced-motion` is set. Create a simple SVG logo `web/public/brand/logo.svg`, and icon `docs/app-store/assets/icon-1200.png` at 1200×1200 with no text, plus favicon. Fair comparison with the manual approach. Legal pages carry a visible placeholder for the business identity the owner supplies.
- [ ] **Step 4:** Run tests. View at desktop and 375 px in the built-in browser.
- [ ] **Step 5:** Commit: `feat(site): landing, docs, privacy, terms`.

---

### Task 15: App config and dev-store deployment

**Files:** Create `shopify.app.bogo.toml`; Modify `extensions/bogo-discount/shopify.extension.toml` name, `web/config/shopify.php`.

The extension rename affects the older configs on their next deploy, so `SHOPIFY_FUNCTION_TITLE` stays per-environment and no old config is deployed from this branch.

- [ ] **Step 1:** Create a new Dev Dashboard app named `Pairly: BOGO Offers` by `shopify app config link`, saved as `bogo`. Stop if a sign-in is needed. Do not select any distribution.
- [ ] **Step 2:** Set scopes `write_discounts,read_products`, optional `read_orders`, webhooks including `app_subscriptions/update`, `automatically_update_urls_on_dev = false` only once a real URL exists.
- [ ] **Step 3:** Run `shopify app dev --config bogo --store levoilest.myshopify.com`. Expected: the Function preview and the page load.
- [ ] **Step 4:** Commit: `chore: Pairly app config`.

### Task 16: Security review and dev-store QA

**Files:** Create `docs/security/2026-10-31-review.md`, `docs/qa/pairly/README.md`, `docs/qa/pairly/screenshots/**`.

- [ ] **Step 1:** Run `composer audit`, `npm audit --omit=dev` and the `security-review` skill. Fix with TDD, one commit each.
- [ ] **Step 2:** Browser QA: §4.4 table in both methods at real checkout, tag and collection, schedule, coupon on and off, offer off, billing test charge and cancel, optional scope grant and decline, uninstall and reinstall, Arabic admin and storefront, phone width. Screenshots with expected and observed.
- [ ] **Step 3:** List under "Not verified" anything not exercised: at minimum a real non-Plus store, which waits for Shopify's approval and the owner's word.
- [ ] **Step 4:** Commit: `qa: Pairly dev-store run`.

### Task 17: Submission package and hand-off

**Files:** Create `docs/deploy-pairly.md`, `docs/app-store/listing.md`, `docs/app-store/requirements-evidence.md`, `docs/app-store/reviewer-instructions.md`, `docs/app-store/assets/**`.

- [ ] **Step 1:** Create a new Cloudways application on the PROMAX server, without changing any existing application, and deploy this branch: Hybrid, web root `web/public`, Varnish off, HTTPS, its own database and `.env` with keys including `SITE_URL`, empty `SHOPIFY_ALLOWED_SHOPS`. Point `shopify.app.bogo.toml` at its temporary URL and `shopify app deploy --config bogo`. Smoke test on the dev store. Write the deploy guide with the version log. Stop if a password is required.
- [ ] **Step 2:** Walk https://shopify.dev/docs/apps/launch/app-requirements-checklist item by item into `requirements-evidence.md`: met with evidence, or waiting on owner.
- [ ] **Step 3:** Draft the listing within limits: intro 100 characters or fewer, details 500 or fewer, feature lines 80 or fewer, and icon and 3–6 screenshots at the stated sizes. Compare market prices and report findings to the owner without changing the price.
- [ ] **Step 4:** Final message in Arabic: confirmed, not confirmed, needed from the owner: Cloudways server, domain, business identity, Public distribution approval, submission, review replies, post-approval Fashion Avenue check, cleanup from spec §15.
- [ ] **Step 5:** Commit: `docs: Pairly submission package`.
