# Final whole-branch review: Le Voile BOGO offer app (01f62f7..ed2bbfb)

Reviewer: Senior Code Reviewer (read-only). Inputs: spec, plan (Global Constraints, Review Focus), `final-review.diff`, ledger lines, `docs/qa/README.md`, `docs/qa/test-output/*`, `docs/security/2026-10-30-review.md`, `shared/fixtures/bogo-cases.json`, `web/tests/Fixtures/graphql/orders_all_rows.json`. I ran no tests. The recorded outputs were enough, and the one doubt I had (Function instruction budget) cannot be measured without running a build.

**Overall:** strong, carefully tested work. The discount math is right everywhere I checked. JS and PHP agree, half-up rounding is exact in both, "never raise a price" holds, odd and even carts are handled, and the brute-force test plus 21 live checkout scenarios back it up. The security seams are closed: every `/api/*` route is bearer-only, the limiter runs after auth, CSP is global, and the CSRF exception is exactly `webhooks`. The cross-component contracts match: the strings come from `shared/offer-constants.json`, the metafield schema is identical on both sides, and the `AnalyticsResult` and `/api/settings` keys match what `app.js` reads.

The open risks are operational. Large carts can exceed the Function's instruction budget. Analytics counts cancelled orders. A save can report success while the Shopify discount is inactive. The deploy guide has a gap at the irreversible distribution step.

**Counts:** Critical 0, Important 5, Minor 17.

---

## Critical

None.

## Important

**I1. `extensions/bogo-discount/src/bogo.js:54-69` (`computeOffer`), plus per-unit expansion in `expandUnits`/`planLineDiscounts`: large carts can exceed Shopify's Function instruction limit, and then the customer gets no discount at all.**
- **What:** `docs/qa/test-output/function-run.txt` measures the cost. Even carts cost about 0.134M instructions per unit (n4 = 1.07M, n12 = 2.14M). Odd carts add roughly 0.011M·n² on top (odd overhead: n5 ≈ +0.37M, n7 +0.62M, n9 +0.94M, n11 +1.31M, consistent with the n candidates × O(n) `filter` + `pairUp` + `reduce` in `build`). Extrapolating, an **odd** cart of about 25–29 units, or an **even** cart of about 80 units, reaches the 11M instruction limit for discount Functions. The limit scales with line count, not quantity, so one line of `qty 27` is the bad case.
- **Why it matters:** when a Function fails, Shopify applies nothing. The shopper who adds 27 scarves pays full price instead of about 50% off, the exact opposite of "the customer must win" (§1, anti-goal 1). Bulk carts are plausible for a store-wide BOGO in a headscarf store. The security review (minor 4) and ledger Task 2 both noted this, but nobody measured it.
- **Suggested fix:**
  1. Make the odd case O(n). In the sorted array, removing index i pairs everything before i on even offsets and everything after i on odd offsets. Prefix sums of `sorted[2k].pNow` and suffix sums of `sorted[2k+1].pNow` give each candidate's gift total in O(1).
  2. Avoid per-unit object churn: work on (line, count) runs, or build `assignments` only once for the winning index.
  3. Add fixtures with 27, 51, 99 and 199 units (one line and many lines) to `generate-fixtures.mjs`, and record their instruction counts in `function-run.txt` as a regression budget (say < 5M).

**I2. `web/app/Services/Analytics/OrdersFetcher.php:50` (query) and `OfferAnalytics.php:102` (`quantity`): analytics counts cancelled and refunded orders as offer cost.**
- **What:** the orders query filters only by `created_at`. Cancelled orders, fully refunded orders and refunded or removed line items (`quantity` is the original quantity, not `currentQuantity`) all feed four of the five numbers: the offer-orders count and %, gifts, total discount and scenario 2.
- **Why it matters:** "what did it cost us" is overstated by every cancelled order. On a cash-on-delivery-heavy Egyptian store that can be a large share of orders. The anti-goal says the numbers must not disagree with Shopify's own order data. Nothing in the fixtures or QA covers a cancelled order.
- **Suggested fix:**
  1. Exclude cancelled orders: add `-status:cancelled` to `$q`, or fetch `cancelledAt` and skip those orders. Exclude `test:true` orders the same way.
  2. Decide how refunds count. Simplest: keep original allocations (the discount was granted) but say so in a tile footnote. Alternatively, use `currentQuantity` for the recompute paths.
  3. Add a fixture order with `cancelledAt` set and assert it contributes nothing.

**I3. `web/app/Services/AutomaticDiscountManager.php:66` and `SettingsController.php:39`: a save with `enabled: true` reports success while the Shopify discount is not ACTIVE.**
- **What:** `ensure()` returns the existing discount whatever its `status` (EXPIRED, SCHEDULED, or deactivated by a merchant). The controller then shows «تم الحفظ وتم تحديث الخصم في شوبيفاي».
- **Why it matters:** `docs/deploy.md:279` tells operators to use Shopify's **Deactivate** as the fallback kill switch. The next time the owner turns the offer on from the page, they get a green success banner while the offer stays dead at checkout. That is the anti-goal "page reports success when the save did not work". Spec §6.1 says success only after the discount "reports back correctly". The caution status line underneath does not undo a green banner.
- **Suggested fix:** in `ensure()`, when the caller wants the offer enabled and `status !== 'ACTIVE'`, call `discountAutomaticAppUpdate` with `startsAt: now, endsAt: null`, re-read, and throw `ShopifyGraphqlException` (422, red banner) if it is still not ACTIVE. Add a feature test using a `discount-nodes-existing` fixture with `status: EXPIRED`.

**I4. `docs/deploy.md:247` (§6 step 1): the guide skips the custom-distribution step, which is irreversible.**
- **What:** the guide says to open "Distribution/Install" and copy the install link. The ledger (line 68) says distribution was deliberately left unselected and is chosen at go-live, and that the choice is irreversible. The guide never says to pick **Custom distribution**, enter `levoilestores.myshopify.com`, and never pick Public. It also does not say what happens to the existing dev-store install.
- **Why it matters:** this is the one step in the go-live path that cannot be undone. Picking the wrong option means recreating the app, which changes the `client_id`, the `.env`, the toml and the metafield owner.
- **Suggested fix:** add an explicit numbered step: Distribution → Custom distribution → store domain `levoilestores.myshopify.com` → Generate link. State plainly: "irreversible; never choose Public distribution". Record whether the dev store keeps working afterwards.

**I5. `web/app/Services/Analytics/OrdersFetcher.php:56-61` and `HttpGraphqlClient.php:43-46`: analytics fails outright on real order volume if Shopify throttles one page.**
- **What:** `orders(first: 250) { lineItems(first: 250) { discountAllocations … } }` is an expensive query, and pages are fetched back to back. If any page returns a top-level `THROTTLED` error, the whole computation fails with a red banner. There is no retry or backoff.
- **Why it matters:** QA exercised 3 orders, so a single page. For a 30- or 60-day range on the live store, the fetch needs several pages and may hit the cost bucket. The result would be that the analytics block is unusable exactly where it matters. This is unverified either way.
- **Suggested fix:**
  1. Retry pages that fail with `extensions.code === 'THROTTLED'`: sleep based on `extensions.cost.throttleStatus`, at most about 3 tries.
  2. Lower `lineItems(first:)` to around 50. The ledger's "lineItems unpaginated" item stays deferred.
  3. Before declaring analytics done, run the 60-day range once against a store with more than 250 orders, or replay a recorded throttled response in a unit test.

## Minor

**M1. `web/resources/views/home.blade.php:67-74`: the status line contradicts the switch when the offer is off.**
- **What:** with the toggle off it reads «الخصم موجود وشغال في شوبيفاي», QA Part B finding 3. Spec §6.1 is literal ("exists and is active in Shopify"), but merchants read it as "the offer is live".
- **Suggested fix:** when `settings.enabled` is false, show «الخصم موجود في شوبيفاي — العرض متوقف من المفتاح» instead.

**M2. `web/app/Services/OfferSettings.php:47-70`: a save can show a red error even though it took effect.**
- **What:** the metafield is written before `ensure()`. If `ensure()` then fails, the change has already taken effect, for example toggle off, but the page shows a red error.
- **Suggested fix:** run `ensure()` first, then the metafield write and read-back.

**M3. `web/public/app.js:64-67`: 429s and server-side validation errors show the "connection problem" text.**
- **What:** a 429 from the limiter and Laravel's 422 validation shape (`message`/`errors`, no `error`) both fall back to «حصلت مشكلة في الاتصال بالسيرفر». That text is misleading in both cases.
- **Suggested fix:** map 429 to "عدد طلبات كبير، استنى دقيقة". Use `data.message` or the first entry of `errors` when present.

**M4. `OfferSettings.php:67` and `AutomaticDiscountManager.php:44`: two errors the merchant can see are in English on an Arabic page.**
- **What:** `read-back mismatch` and `BOGO discount function was not found…`. Shopify's own errors are rightly verbatim, but these two are ours.
- **Suggested fix:** translate them.

**M5. `web/app/Services/Analytics/OfferAnalytics.php:98-107`: the recompute and scenario-2 paths treat every line item as eligible.**
- **What:** that includes gift cards (the Function excludes them) and lines added by an order edit after checkout. Either can make scenario 2 or the fallback counts drift.
- **Suggested fix:** fetch `product { isGiftCard }` and skip gift-card lines. Consider `currentQuantity`, which ties in with I2.

**M6. `OfferAnalytics.php:139-148`: one percent stands in for the whole range, and the tile label uses yet another source.**
- **What:** `rangePercent` takes the first percent found in the range and uses it for every order without a percent allocation. The tile label in `app.js` comes from the *current* setting. Orders in the range may have run at a different percent.
- **Suggested fix:** acceptable for now, but say "النسبة الحالية" in the footnote.

**M7. `OrdersFetcher.php:50`: the date filter is unquoted and its boundary was never tested.**
- **What:** the ISO timestamp (`…T12:00:00+00:00`) is passed unquoted in the search query, and no QA order was older than 7 days. If Shopify ever ignores the term, every range silently shows 60 days.
- **Suggested fix:** quote it (`created_at:>='…'`). In QA, compare 7 days and 60 days against a store with older orders.

**M8. `web/routes/api.php:10`: the debug route `/api/ping` is still there.**
- **What:** it is authenticated and harmless, but it is dead code.
- **Suggested fix:** remove it, or keep it as a documented health check.

**M9. `shopify.app.toml:31` and `docs/deploy.md` §4: `redirect_urls` points at a route that does not exist.**
- **What:** it points at `/auth/callback`, and the guide's table requires that value. Install uses managed install plus token exchange, so the URL is never hit.
- **Suggested fix:** point it at `https://offer.arenahere.com/`, or comment in the toml that it is unused.

**M10. `shopify.app.toml:11`: `automatically_update_urls_on_dev = true` while dev and live share one app.**
- **What:** the dev store and the live store share one app (`client_id`). Running `shopify app dev` after go-live may rewrite app URLs or push a dev preview that touches the shared config.
- **Suggested fix:** check CLI 4.9.4's dev-preview scope. Safest: a separate dev app with `shopify.app.dev.toml`, or set the flag to false and add a "never run `app dev` against the production app" line to the deploy guide.

**M11. `docs/deploy.md:279`: the guide names the discount by the wrong title.**
- **What:** it says the discount is named by `SHOPIFY_FUNCTION_TITLE`. The discount's title is «اشتري واحدة وخد واحدة», and "BOGO Discount (Le Voile)" is the Function's name.
- **Suggested fix:** correct the text.

**M12. `docs/deploy.md` §5: the pre-launch steps only deactivate discount codes.**
- **What:** G3 also needs any other active **automatic** product discounts deactivated. The dev store had several, which were expired.
- **Suggested fix:** add a pre-launch step for them.

**M13. `extensions/bogo-discount/src/cart_lines_discounts_generate_run.js:22-31`: one §4.5 exclusion is not implemented.**
- **What:** §4.5 also excludes "lines already flagged by Shopify as not discountable". The Function input has no such field, so this is a spec gap that is not called out anywhere.
- **Suggested fix:** document it in the spike or deploy doc as N/A for this API.

**M14. `web/app/Shopify/SessionToken.php:59-63`: no clock-skew leeway on token times.**
- **What:** if the server clock trails Shopify by even a second, a fresh token fails `nbf > now` with a sporadic 401.
- **Suggested fix:** allow 5 s leeway on `nbf` and `exp`.

**M15. `AutomaticDiscountManager.php:47-58`: two concurrent saves can both create a discount.**
- **What:** the client-side `saving` flag protects one tab only. Two tabs, or a retry, can both see "none" and both create one, breaking "never a second discount".
- **Suggested fix:** `Cache::lock("ensure:{$shop}")` around find-or-create.

**M16. `extensions/bogo-discount/tests/bogo.test.js:23-31`: test signals that read as noise.**
- **What:** the "9 skipped" are `it.runIf(scenario2Pay)`, so a permanently non-zero skip count hides accidental skips. `per-line plan matches` also passes vacuously for cases without `perLine`.
- **Suggested fix:** use `describe.each(cases.filter(c => c.scenario2Pay !== undefined))`. In the per-line test, assert that unlisted lines are absent.

**M17. `web/app/Http/Controllers/WebhookController.php:16-17`: webhook headers are trusted after a body-only HMAC.**
- **What:** security review hardening item 1, still open. Topic and shop domain come from headers that the HMAC does not cover.
- **Suggested fix:** for `app/uninstalled`, require the body's `myshopify_domain` to equal the header, and de-duplicate on `X-Shopify-Webhook-Id`. This can wait (fix later).

---

## Deferred minors and parked items: triage

| Ledger item | Verdict | Reason |
|---|---|---|
| T3 fixture format (scaffold helpers instead of `*.expected.json`) | Drop | Same intent; 32/32 harness fixtures pass. |
| Rulings: dev store, Partners org, removal of `cart.delivery-options` target, extra owner scenarios | Drop | Sound and recorded; nothing left to do. |
| Task 2: odd case is O(n²); watch the instruction budget | **Must fix before merge** | Measured counts extrapolate past 11M at about 27 odd units, and failure means no discount (I1). |
| Task 2: no explicit odd tie-break test; min==max only indirect | Fix later | A tie changes only which line is labelled, not the money. Cheap to add with the I1 rewrite. |
| Task 2: perLine absence, half-up .5 boundary, NaN on exponent notation | Fix later | PHP covers .5 (`5*50/100`); Shopify never sends exponent notation. Fold into M16. |
| Task 1: spike discount renamed rather than deleted | Drop | Dev store only; the live store starts clean. |
| Tasks 3 and 4 in parallel | Drop | Process ruling; history is clean. |
| Task 3: `run.test.js` spells «هدية»/«خصم 30%» literally; money helper convoluted; expected amounts via `toAmountString` | Fix later | Literal strings act as an independent oracle; the `money()` helper is the only real smell. |
| `write_products` temporary scope | Drop (done) | `shopify.app.toml:28` now has exactly the 3 scopes; the deploy guide checks it. |
| Task 4: values via `toPiastres`; no non-empty-fixture assertion; unknown constant → warning; strcmp vs UTF-16 | Fix later | Add `assertNotEmpty` on the data provider (one line); ids are ASCII GIDs. |
| Webhooks `api_version` = 2026-07 | Drop (done) | Verified in the toml. |
| Task 12 split; QA10 code on the dev store | Drop | The coupon behaviour was verified (QA "coupon" row). |
| Task 5: `.env.example` DB keys commented; no trailing-slash test | Fix later | Deploy guide §3.3 lists the MySQL keys explicitly. |
| `write_publications` temporary scope | Drop (done) | Removed. |
| Task 7: migrations share timestamp 000001; `down()` fails with null tokens | Fix later | Alphabetical order is deterministic. If you rename, do it before the first production `migrate`, the only free window. |
| Task 8: `discountNodes(first:50)` unpaginated; Function matched by title, not app; loose casts; missing update-error / missing-Function GET tests | Fix later | `type:app` keeps the list tiny; the unused `app { title }` is already fetched, so filtering on it is trivial. |
| Task 9: `lineItems(first:250)`; exception → 422; mixed title/message; no stampede lock; empty product title | Fix later. Exception → 422 and stampede: drop | The 422 goes only to the authenticated merchant; one merchant plus the rate limit makes a stampede moot. Label deleted products «منتج محذوف». Fold `first:250` into I5. |
| Task 10: unpinned `polaris-2.js`; 200 with non-JSON body; `adminUrl` scheme; "%" RTL nit | Drop (polaris, adminUrl); fix later (non-JSON, RTL) | Shopify's App Home CDN scripts are unversioned by design; `adminUrl` is built on the server. |
| Protected customer data request (Draft) | Drop | Needed and documented in the deploy guide. |
| `npm audit`: 25 high in build-time codegen | Fix later (accept now) | Not in the shipped Wasm, and nothing at runtime uses npm. Record the owner's acceptance and re-check on the next `@shopify/shopify_function` bump. |
| Task 11: review doc cites line numbers | Drop | Cosmetic. |

## Requirements coverage

- **§1 / G1, automatic store-wide discount:** `extensions/bogo-discount/src/cart_lines_discounts_generate_run.js`; QA 21/21 checkout scenarios.
- **G2, compare-at as full price; never raise a price:** `discountableLines` (pFull = max), `singleDiscount` (`max(0, …)`) in JS and PHP; fixtures `markdown_single_650/800`, `markdown_gift_800`.
- **G3, no combining:** `AutomaticDiscountManager.php` CREATE has `combinesWith` all false and is tested; QA "coupon" row. Other automatic discounts are not covered in the deploy guide (M12).
- **G4 / §6.1, one Arabic RTL page:** `home.blade.php`, `app.js`, `app.css`; QA Part B, including phone width. Status-line semantics: M1. Success gating: I3.
- **G5 / §5.4 / §8, tests and E2E:** `bogo.test.js`, `bogo.bruteforce.test.js` (300 random carts plus fixtures ≤ 9 units), `run.test.js`, 32 harness fixtures, 120 PHP tests, `docs/qa/`. Gap: QA ran against the dev preview, not the deployed version (stated in QA "Not verified").
- **G6 / §9, Cloudways deploy:** `docs/deploy.md`; distribution gap I4; doc nits M9, M11, M12.
- **G7 / §7, security:** `docs/security/2026-10-30-review.md`, 8/9 ✅; `npm audit` accepted (triage).
- **§3 scopes and 60-day window:** `shopify.app.toml:28` (3 scopes); ranges {7,30,60} in `shared/offer-constants.json` and `AnalyticsController.php:17`.
- **§4.1–4.3 algorithm and rounding:** `bogo.js`, `money.js`, `web/app/Offer/BogoCalculator.php` (exact integer half-up); shared fixtures drive both. Performance at scale: I1.
- **§4.4 worked examples and edge cases:** `shared/fixtures/bogo-cases.json` (27 cases, including the optimality-with-markdowns case), the brute-force test and QA.
- **§4.5 eligibility:** variant and gift-card filters plus metafield gating (18 gating cases). "Not discountable" flag: GAP, not expressible in this API (M13).
- **§5.1 single discount and title:** `ensure()` with the drift rename; tests `second_save_does_not_create_a_duplicate`, `drifted_title_is_renamed`. Concurrency: M15.
- **§5.2 message contract:** `shared/offer-constants.json`, consumed by the Function, `OfferConstants.php` and analytics `classify()`; partial-quantity targets verified by the spike and QA.
- **§5.3 metafield config and read-back:** `OfferSettings.php` (write, read back, mismatch → 422); the Function reads `$app:bogo/config`.
- **§6.1 block 2, tiles, table, scenario 2:** `OfferAnalytics.php`; fixtures reproduce the §4.4 rows (9 orders, including a foreign code); QA tiles match 3 real orders. Cancelled orders: I2. Volume: I5. Optional profit tile: dropped (allowed).
- **§6.2 live, 10-minute cache, cap 2000, compare-at source:** `OfferAnalytics::compute` (`Cache::remember`), `OrdersFetcher` (cap and `capped`), blade footnote.
- **§6.3 auth, lifecycle, allow-list:** `VerifySessionToken`, `TokenExchange`, `VerifyAdminQueryHmac`, `Shop::isAllowed`, `WebhookController` (uninstall plus 3 compliance topics).
- **§9 install with the offer off:** with no metafield, `read()` returns `enabled:false`, the page shows «متوقف» and "missing — press save" (`SettingsTest::test_get_returns_defaults_and_null_discount_without_creating`); the Function returns nothing.
- **§10 risks:** 1 spike GO (`docs/spike/`); 2 coupon QA ✅; 3 ranges capped ✅; 4 hand-rolled auth ✅; 5 hand-off points in the deploy guide ✅.

## Strengths

Exact money handling. One shared fixture file drives the JS algorithm, the PHP port, the Function harness, the analytics fixtures and the live QA, and a brute-force oracle sits on top. Discounts fail closed on every malformed config.

The security work is disciplined. Each finding was reproduced with a failing test before it was fixed (F1–F3). The limiter-before-auth bug in particular was subtle and was caught.

---

## Final fix wave

All five Important findings fixed, one commit each, on top of `ed2bbfb`. Final suites: `cd web && php artisan test` → **129 passed (519 assertions)** (was 120); `cd extensions/bogo-discount && npx vitest run` → **4 files, 200 passed, 9 skipped** (includes the Wasm harness on 36 fixtures); `shopify app function run` loop → **36/36 fixtures match** (`docs/qa/test-output/function-run.txt`).

### I1 (Function instruction budget): `783f792` fix(function): plan on line runs and pick the odd single in O(n) (I1)
- Files: `extensions/bogo-discount/src/bogo.js`, `tests/bogo.bruteforce.test.js`, `tests/generate-fixtures.mjs`, `tests/fixtures/bulk_qty27.json`, `bulk_qty51.json`, `bulk_qty99.json`, `bulk_5lines_199.json`, `docs/qa/test-output/function-run.txt`.
- Change: `computeOffer`'s odd search is O(n): removing sorted index i leaves gifts at the even indices k < i plus the odd indices k > i, so each candidate is `evenBefore[i] + oddAfter[i] + singleDiscount`. Same tie-breaks, and only the winner is built. `planLineDiscounts` (the only path the Function runs) now works on (line, quantity) runs and never expands units. Inside a run every single placement saves the same amount, so each line's first unit is the only candidate. Gift counts per line come from even/odd index counts. `expandUnits` shares one object per line.
- Covering tests: the old O(n²) `computeOffer` and the old per-unit `planLineDiscounts` are kept as **test-only references** in `bogo.bruteforce.test.js`. The new code must match them: 500 seeded carts up to 13 units, `max` and `min`, same totals, same `singleUnit` object, same `assignments`; 500 + 200 seeded carts for the plan, including large quantities, free items and equal prices. All existing fixture and brute-force tests are unchanged and pass. Mutation check: flipping the pFull tie rule to `<=` fails both new tests.
- Harness fixtures were hand-derived in `generate-fixtures.mjs` (one line × qty 27/51/99 at 600, and 40×100, 40×200, 40×300, 40×400, 39×500 at 30%, saving 29650).
- Instructions (`shopify app function run … --json`):

  | input | before | after |
  |---|---|---|
  | bulk_qty27 (1 line) | 8,047,162 | 753,368 |
  | bulk_qty51 | 24,186,259 (over the 11M limit) | 753,086 |
  | bulk_qty99 | 83,272,051 | 753,086 |
  | bulk_5lines_199 | 323,445,127 | **1,524,874** |
  | n11 | 3,322,329 | 2,194,052 |
  | n12 | 2,141,813 | 2,054,709 |

  Cost no longer depends on quantity, only on line count, which is dominated by input parsing.
- Command: `npx vitest run tests/bogo.test.js tests/bogo.bruteforce.test.js tests/run.test.js` → `Tests 164 passed | 9 skipped`.

### I2 (cancelled orders in analytics): `551d42b` fix(analytics): exclude cancelled orders (I2)
- Files: `web/app/Services/Analytics/OrdersFetcher.php` (`cancelledAt` in the query, `-status:cancelled` appended to `$q`, nodes with non-null `cancelledAt` skipped), `web/resources/views/home.blade.php` and `web/tests/Feature/HomePageTest.php` (the scenario-2 footnote that already existed gains «الأوردرات الملغية مستبعدة، والمرتجعة محسوبة لأن الخصم اتمنح وقت الشراء»), `web/tests/Feature/AnalyticsTest.php`, `web/tests/Fixtures/graphql/orders_with_cancelled.json`.
- Covering tests: `test_cancelled_offer_order_counts_nowhere`. The fixture is orders_all_rows plus a cancelled #1999 with a 5000 gift and a 300 single. Every tile stays identical to orders_all_rows (9 / 7 / 77.8% / 8 / 4 / 9370 / 390), and "Product Cancelled" is absent from topGifts. `test_orders_query_excludes_cancelled_orders` asserts `-status:cancelled` in `q`. Both fail on the old fetcher. Refunds are kept as-is, and test orders are not excluded.
- Command: `php artisan test --filter=AnalyticsTest` → `17 passed (117 assertions)`.

### I3 (save succeeds with an inactive discount): `cfe7fc3` fix(settings): reactivate a non-ACTIVE discount before reporting success (I3)
- Files: `web/app/Services/AutomaticDiscountManager.php` (`ensure()`: if status ≠ ACTIVE → `discountAutomaticActivate(id:)` → re-read → throw `ShopifyGraphqlException` on userErrors or when still not ACTIVE; English message names the status), `web/tests/Support/FakeGraphqlClient.php` (ActivateDiscount op; after success serves `$afterActivate`), `web/tests/Feature/SettingsTest.php`, fixtures `discount-nodes-expired.json`, `discount-nodes-scheduled.json`, `discount-activate-ok.json`, `discount-activate-error.json`. `SettingsController` needed no change, because the exception already maps to 422 `{error}`.
- Covering tests: `test_expired_discount_is_reactivated_before_reporting_success` (200, ops `… AppDiscounts, ActivateDiscount, AppDiscounts`, status ACTIVE, no create), `test_activation_user_error_returns_422_with_that_text`, `test_discount_still_inactive_after_activation_returns_422` (SCHEDULED). `test_second_save_does_not_create_a_duplicate_discount` is unchanged and passes.
- Command: `php artisan test --filter=SettingsTest` → `17 passed (101 assertions)`.

### I4 (deploy guide distribution step): `843c8e9` docs(deploy): add the irreversible Custom distribution step before install (I4)
- Files: `docs/deploy.md`. §6 gets a new step 1: Partner Dashboard → offer-app → Distribution (URL given) → **Custom distribution** → `levoilestores.myshopify.com` → Generate link. It states that the choice is irreversible and Public must never be chosen (that would mean a new app and `client_id`, `.env`, toml and metafield owner). Custom distribution grants protected customer data automatically, and the dev store keeps working via `shopify app dev`. The install steps are renumbered 2–6. §7 "إيقاف الخصم من Shopify مباشرة" now notes that the next save reactivates the discount (I3).
- Covering test: none (docs).

### I5 (no retry when throttled): `e9adb4c` fix(shopify): retry throttled GraphQL requests with backoff (I5)
- Files: `web/app/Shopify/HttpGraphqlClient.php` (constructor takes an optional `callable $sleep`, default `usleep`; a 429 or `errors[].extensions.code == THROTTLED` is retried after 500 ms, 1 s and 2 s, so 4 calls at most, then the normal exception path; connection errors and other errors behave as before), `web/tests/Unit/Shopify/HttpGraphqlClientTest.php`.
- Covering tests: throttled + 429 then success → data, `assertSentCount(3)`, slept `[500000, 1000000]`; throttled ×4 → exception, 4 calls, slept `[500000, 1000000, 2000000]`; 429 ×4 → exception, 4 calls; non-throttle error → exception, 1 call, no sleep. The existing 7 tests are unchanged.
- Command: `php artisan test --filter=HttpGraphqlClientTest` → `11 passed (21 assertions)`.
- Not done: `lineItems(first: 250)` in `OrdersFetcher` was left as it is (the review's optional step 2). A 60-day run against a store with more than 250 orders is still unverified live.
