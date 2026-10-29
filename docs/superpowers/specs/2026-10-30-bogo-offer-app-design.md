# Le Voile "Buy One Get One / 30%" Offer App — Design

**Date:** 2026-10-30
**Owner:** Arena (agency) for Le Voile Stores — Shopify store `levoilestores.myshopify.com` (shopId `85284847908`)
**Project folder:** `C:\xampp\OfferApp` (empty at the time of writing — this document is the first file)
**Status:** approved design, ready for planning (`superpowers:writing-plans`)

---

## 1. Intent — what this is and why

Le Voile wants to run an aggressive store-wide promotion on its Shopify storefront
(web + the Flutter app's hosted checkout):

> **"Buy one, get one free — or buy one piece alone and get 30% off."**

Shopify's built-in "Buy X Get Y" discount cannot express the combination the owner
wants (a gift pairing PLUS a 30% leftover unit in the same cart, always computed on
the product's **original** price). So this project builds a small **custom Shopify
app** that:

1. Applies the promotion automatically in cart and checkout through a **Shopify
   Discount Function** (no discount code; the customer does nothing).
2. Gives the merchant **one embedded page inside Shopify Admin** to switch the offer
   on/off, change the percentage, and read five numbers that answer "did the offer
   work, and what did it cost us".

The owner's explicit priority, stated several times: **the customer must win.**
When two valid ways of applying the offer exist, the app picks the one that gives
the customer the larger discount. Margin protection is a non-goal.

### Why now
The owner is about to remove most existing markdowns and return the catalogue to
base prices, then launch this offer as the single store-wide promotion.

### What success looks like
- A shopper adds 1, 2, 3, 4, 5… units of anything and the cart/checkout totals match
  the worked examples in §4.4 to the piastre, every time, with no code to type.
- The merchant opens one page, flips one switch, and sees whether the offer is
  live and what it did last week.
- The app ships with **zero known defects**: automated tests for the discount
  math, Laravel feature tests for the admin page, and real end-to-end runs on a
  development store with screenshots of each scenario handed to the owner.

---

## 2. Goals, non-goals, anti-goals

### Goals
- G1. Automatic, store-wide discount implementing the rule in §4 exactly.
- G2. Always compute on the product's **original (compare-at) price** when one
  exists; never raise a price.
- G3. The offer does **not** combine with any other discount (codes, other
  automatic discounts, shipping discounts). When the offer is on, it is the only
  product discount that applies.
- G4. One embedded admin page (Arabic, RTL) with: on/off, percent, save, and the
  analytics block described in §6.
- G5. Full automated test coverage of the discount function; E2E screenshots per
  scenario from a dev store.
- G6. Deployable on the agency's existing **Cloudways** PHP hosting (same account
  that hosts the Social CRM), with a written deploy guide.
- G7. Security reviewed: HMAC-verified OAuth and webhooks, session-token
  authenticated admin page, minimal scopes, mandatory compliance webhooks.

### Non-goals (explicitly out of scope for this delivery)
- No order storage, no order webhooks, no database beyond what the Shopify
  Laravel package needs for shop/session records. Analytics are computed **live
  from Shopify's Admin API when the page is opened** (§6).
- No A/B test between the two scenarios. (Candidate for a later phase; the owner
  agreed to collect data first.)
- No "what sales volume would have been" estimates. The owner removed this; only
  computed facts are shown.
- No per-product / per-collection scoping. The offer is store-wide.
- No public App Store listing. This is a **custom app** installed on the Le Voile
  store only (and a dev store for testing).
- No integration into the Social CRM codebase. The owner chose a standalone
  embedded page instead.
- No support for Shopify POS, draft orders, or subscriptions.

### Anti-goals (things that would make this a failure even if it "works")
- A cart where the customer would have paid **less** under a different valid
  pairing than the one the function chose.
- Charging more than the displayed/current price for any unit (e.g. applying
  "70% of compare-at" to an item already marked below that).
- The offer silently stacking with a coupon code so a customer gets both.
- An admin page that reports success when the save to Shopify actually failed.
- Numbers on the analytics block that disagree with Shopify's own order totals.

---

## 3. Constraints and existing material

- **Team stack:** PHP/Laravel (Laravel 12 across the agency's other projects: Social
  CRM, PROMAX, Le Voile dashboard). The embedded page is therefore built in
  Laravel so it deploys to Cloudways exactly like the other apps. Node is used only
  for Shopify CLI and the Function's build/test tooling.
- **Tooling present on the dev machine:** Shopify CLI 4.9.4, Node 26.3, npm 11.16,
  XAMPP (PHP 8.2+, MySQL).
- **Hosting:** Cloudways, PHP application, **Hybrid** stack (so `.htaccess` is
  honoured — the Lightning/Nginx-only stack ignores it; the Le Voile dashboard
  README learned this the hard way). MySQL from the same server.
- **Accounts the builder will need from the owner (cannot be created by the
  agent):** a Shopify Partners account with a **development store**, and later
  the owner's explicit go-ahead before installing on the live Le Voile store.
  The agent never types the owner's passwords; the owner signs in once in the
  browser when the CLI asks.
- **Storefront context:** Le Voile also sells through a Flutter app (FluxStore)
  that uses Shopify's **hosted web checkout**. A Discount Function applies there
  automatically — nothing in the Flutter app needs to change. The cart drawer
  inside the app shows Storefront-API cart totals, which also reflect automatic
  discounts.
- **Existing Shopify discounts on the store:** the Le Voile dashboard mints real
  Shopify discount codes (welcome/online coupons, `usage_limit=1`). G3 says these
  must not stack with the offer. **Owner decision (2026-10-30):** while the offer
  runs, coupon distribution is paused and existing codes are deactivated, so a
  code can never displace the offer at checkout. That work happens in the
  Le Voile dashboard / Shopify Discounts screen, outside this repo; the deploy
  guide lists it as a pre-launch step, and QA verifies on the dev store what a
  shopper sees when typing a code while the offer is on.
- **App ownership and hostname (designer's defaults — the build session confirms
  these with the owner before the first `shopify app` command):** the app is
  created under **Arena's** Shopify Partners organisation (the agency operates
  it after delivery), and the Laravel page is served from an Arena subdomain on
  Cloudways, placeholder **`offer.arenahere.com`**. Both values are
  configuration (`shopify.app.toml`, `APP_URL`, redirect URLs, CSP
  `frame-ancestors`) and must not be hardcoded anywhere else.
- **Scopes:** keep to the minimum: `write_discounts` (to create/update the
  automatic discount that hosts the function), `read_orders` (analytics), and
  the metafield scopes the Function's configuration needs. Note `read_orders`
  only returns the last **60 days**; the analytics ranges are therefore 7 / 30 /
  60 days, never 90.

---

## 4. The discount rule (the heart of the project)

### 4.1 Vocabulary
- **Unit:** one physical piece. A cart line with quantity 3 is three units.
- **Full price (`P_full`):** the variant's compare-at price if set and greater
  than the current price; otherwise the current price.
- **Current price (`P_now`):** what the variant sells for right now (the price the
  storefront displays).
- **Percent (`pct`):** the "buy one alone" discount, default **30**, editable on
  the admin page (integer 1–99).

### 4.2 Algorithm (what the Function computes for a cart)
1. Expand every eligible cart line into units. Each unit carries `P_full`,
   `P_now`, and its line id.
2. Sort units by **`P_now`** ascending (ties: by `P_full` ascending, then line id —
   deterministic). Sorting and pairing use the money the customer actually pays
   today; `P_full` is used only to compute the single unit's target price (§4.3).
   Rationale: with units sorted by `P_now`, pairing adjacent units and gifting the
   cheaper of each pair is provably the maximum total gift value among all
   pairings where the gift is the cheaper unit — so "customer wins" holds even
   when some products carry a compare-at markdown. (Sorting by `P_full` instead
   can give a worse deal when `P_now ≠ P_full`; see §4.4 edge cases.)
3. Let `n` = number of units.
   - `n = 0` → no discount.
   - **If `n` is odd:** try every unit `u` as the "single" unit. Remove it, pair the
     remaining `n−1` units (even) as in step 4, and compute the customer's total
     saving. Keep the `u` that yields the **largest** total saving. (Ties: prefer
     the cheapest `u` by `P_full`.)
   - **If `n` is even:** no single unit; go straight to step 4 with all units.
4. **Pairing (even count):** walk the sorted list in adjacent pairs
   `(u1,u2), (u3,u4), …`. In each pair the **cheaper** unit (lower `P_now`) is the
   **gift** — it becomes free. The other unit is paid in full.
5. **The single unit** (odd case) gets `pct` % off its full price:
   target price = `P_full × (1 − pct/100)`.

### 4.3 Translating to money — the "never raise a price" rule
The Function can only *reduce* a line's current price. Amounts per unit:
- **Gift unit:** discount = `P_now` (the unit becomes free).
- **Single unit:** discount = `max(0, P_now − P_full × (1 − pct/100))`.
  If the item is already selling below `P_full × 0.7`, the discount is 0 and the
  customer pays `P_now`. We never raise it to 70% of full price.
- **Paid unit:** discount = 0.

Because the saving the algorithm maximises in step 3 must be the **money the
customer actually saves**, the odd-case search uses these money amounts (not
abstract `P_full` values) when comparing candidates.

Rounding: compute in the shop's currency minor units (piastres); final per-line
amounts rounded to 2 decimals with half-up rounding. The sum of per-line
discounts is what Shopify will subtract; tests assert exact totals.

### 4.4 Worked examples (acceptance tests — all on full price, `pct = 30`)

Prices: A=1200, B=1100, C=900, D=800, E=600.

| Units in cart | Gifts | 30% on | Customer pays | Saving |
|---|---|---|---|---|
| A | — | A → 840 | **840** | 360 (30%) |
| A, C | C | — | **1200** | 900 |
| A, B, C | B | C → 630 | **1830** | 1370 (42.8%) |
| A, B, C, D | D, B | — | **2100** | 1900 (47.5%) |
| A, B, C, D, E | D, B | E → 420 | **2520** | 2080 (45.2%) |
| A ×2 (same product, qty 2) | A | — | **1200** | 1200 |
| A ×3 | A | A → 840 | **2040** | 1560 |

Explanation of the 3-unit row (the owner's own scenario): candidates for the
single unit are C (then pair A–B → B gift → pays 1200+630 = 1830), B (pair A–C →
C gift → pays 1200+770 = 1970), A (pair B–C → C gift → pays 1100+840 = 1940). The
customer wins with 1830, so C takes 30% and B is the gift.

Full-price edge cases the tests must also cover:
- Variant with `P_full=1000`, `P_now=800` as the **single** unit → target 700 →
  discount 100 → pays 700.
- Variant with `P_full=1000`, `P_now=650` as the single unit → discount 0 → pays
  650 (never raised).
- Variant with `P_full=1000`, `P_now=800` as a **gift** → discount 800.
- Sorting uses `P_now`: a unit with `P_full=1000/P_now=500` sorts **below** a
  unit with `P_full=900/P_now=900`, and is the gift when the two are paired.
- Optimality with markdowns, 4 units `(P_full, P_now)`: a=(500,10), b=(510,500),
  c=(520,20), d=(530,530). Sorted by `P_now`: a(10), c(20), b(500), d(530) →
  pairs (a,c) and (b,d) → gifts a and b → saving 510, customer pays 550. Sorting
  by `P_full` would have paired (a,b),(c,d) → gifts a and c → saving only 30.
  The brute-force property test in §5.4 must confirm no pairing beats 510.
- Mixed cart of 2 lines, quantities 2 and 3 (5 units) — the discount must land on
  the right **number of units within each line** (see §5.2).

### 4.5 Eligibility
- All products, all variants, store-wide.
- Excluded: lines whose merchandise is not a product variant, gift cards, lines
  already flagged by Shopify as not discountable.
- The offer applies only when the admin switch is **on** (read from the shop
  metafield in §5.3). When off, the Function returns no discounts.

---

## 5. Component A — the Shopify app and Discount Function

### 5.1 App shape
- A single Shopify **custom app** created through the Partners dashboard / CLI,
  living in `C:\xampp\OfferApp`. The folder is one git repository containing:
  - `extensions/bogo-discount/` — the Discount Function (JavaScript, compiled to
    Wasm by the Shopify CLI toolchain; Rust is not required and the team is JS/PHP).
  - `web/` — the Laravel application for OAuth, the embedded page, webhooks.
  - `docs/` — this design, the plan, the deploy guide, QA screenshots.
  - `shopify.app.toml` and the extension's `shopify.extension.toml`.
- The Function is attached to **one automatic discount** created by the app (title
  shown to customers, in Arabic: **«اشتري واحدة وخد واحدة»**). The admin page
  creates it on first save and updates it afterwards; it never creates a second
  one. The discount's combine settings are all **off** (G3).

### 5.2 Function input/output notes (for the builder to verify against the
current Discount Function API version the CLI scaffolds)
- Input query needs per line: id, quantity, `cost.amountPerQuantity`,
  `cost.compareAtAmountPerQuantity`, merchandise type/product/variant ids, and the
  shop metafield holding configuration.
- Output: product discounts targeted per **cart line with an explicit unit
  quantity** (the Discount API supports targeting `cartLine { id, quantity }`), using
  `fixedAmount` values computed in §4.3. Each discount carries a `message`:
  **`هدية`** for gift units and **`خصم 30%`** (with the live percent) for the
  single unit. These messages are what the analytics block later uses to classify
  order lines, so their exact strings are a contract — define them once in a
  shared constants file and reference them from the Function, the Laravel code and
  the tests.
- If the API version scaffolded by the CLI cannot target a partial quantity on a
  line, the builder must stop and report rather than approximate (this is the one
  technical risk in the project; see §10).

### 5.3 Configuration storage
- A shop-level, app-owned metafield (namespace `$app:bogo`, key `config`, JSON):
  `{ "enabled": true, "percent": 30, "updated_at": "…" }`.
- The Function reads it in its input query; the Laravel page writes it via the
  Admin GraphQL API and then reads it back to confirm the save (anti-goal: no false
  success).

### 5.4 Function tests
- Unit tests (Vitest or the CLI's test runner) for the pure algorithm with the
  §4.4 table and edge cases, plus property-style checks: the chosen pairing is
  never beaten by any other valid pairing for carts up to 7 units (brute-force
  comparison).
- `shopify app function run` fixture tests with real input JSON for each §4.4 row.

---

## 6. Component B — the embedded admin page (Laravel)

### 6.1 One page, two blocks, Arabic RTL
Rendered inside Shopify Admin with App Bridge + Polaris web components (or the
Polaris CSS the chosen Laravel package integrates with — builder's choice, but it
must look native to Shopify Admin and work at phone width).

**Block 1 — الإعدادات**
- Toggle: **العرض شغال / متوقف**.
- Field: **نسبة الخصم على القطعة الفردية** (%), integer 1–99, default 30.
- Button: **حفظ**. On success show "تم الحفظ وتم تحديث الخصم في شوبيفاي". On
  failure show the Shopify error verbatim in red. Success is only shown after the
  metafield **and** the automatic discount both report back correctly.
- A one-line status: whether the automatic discount exists and is active in
  Shopify, with a link to it in Discounts.

**Block 2 — الأرقام** (range selector: 7 / 30 / 60 days, default 30)
Five stat tiles and one small table, computed live (§6.2):
1. **أوردرات أخدت العرض** — count, and % of all orders in range.
2. **هدايا** — number of gift units given.
3. **خصم 30%** — number of single units discounted.
4. **إجمالي الخصم** — EGP total discount granted by the offer.
5. **السيناريو التاني كان هيدخل زيادة** — EGP: for each offer order,
   recompute the cart with the *margin-protecting* variant of the rule and sum
   the difference versus what was actually charged. Definition of scenario 2:
   same unit expansion and sorting as §4.2; for an **even** unit count it is
   identical to scenario 1 (difference 0); for an **odd** count the single
   `pct`% unit is the candidate that yields the **smallest** customer saving
   (the mirror image of §4.2 step 3), the rest paired as in step 4. For the
   owner's 3-unit example this gives C gift + B at 30% = 1970 vs 1830 charged →
   140 EGP. The tile is therefore never negative. Label it clearly as a
   recalculation on the same orders; footnote that even-count orders contribute 0.

Small table: top 10 products that went out as gifts (title, units, EGP value).

Optional sixth tile — **صافي الربح التقريبي**: shown only if Shopify unit costs
(`inventoryItem.unitCost`) exist for the products involved; otherwise show the
tile with the text "أدخل تكلفة المنتجات في شوبيفاي ليظهر الربح". Builder may drop
this tile if it complicates the first delivery; the owner did not insist on it.

### 6.2 How the numbers are computed (no storage)
- On page load (and on range change) the Laravel backend queries Admin GraphQL
  `orders` for the range, paginated (250/page), reading per line item:
  `originalUnitPriceSet`, quantity, variant id, product title, and
  `discountAllocations { allocatedAmountSet discountApplication { … title } }`.
- An order "took the offer" if any allocation's application is our automatic
  discount (match by title; the Function's messages identify gift vs percent
  units per allocation).
- Cache the computed result per shop+range for 10 minutes (Laravel cache).
- Cap at 2,000 orders per range; if the cap is hit, show the tiles with a note
  "محسوب على أول 2000 أوردر".
- Scenario-2 recalculation uses `originalUnitPrice` as `P_now` and, for `P_full`,
  the variant's current `compareAtPrice` when set; the tile footnote states this.

### 6.3 Auth and app lifecycle
- OAuth install + session-token (App Bridge) authentication for every page and
  XHR, via a maintained Laravel Shopify package (e.g. `kyon147/laravel-shopify`) —
  builder confirms the package supports the current Shopify API version and
  session tokens before adopting it.
- Webhooks: `app/uninstalled` (clean the shop record) and the three mandatory
  compliance webhooks (`customers/data_request`, `customers/redact`, `shop/redact`)
  — answer 200 and log; the app stores no customer data.
- Only the Le Voile shop (and the dev store) may install: an allow-list of shop
  domains in config, rejecting any other OAuth attempt.

---

## 7. Security requirements (review checklist for the security session)
- HMAC verification on OAuth callback and every webhook; reject on failure with
  401 and no body detail.
- All admin routes require a valid session token; no cookie-only fallback inside
  the iframe.
- CSRF on form posts; GraphQL calls server-side only — the browser never holds an
  Admin API token.
- Secrets only in `.env`; `.env.example` lists every key with placeholders.
  Nothing hardcoded (the Le Voile dashboard's committed fallback password is the
  anti-pattern to avoid).
- Input validation: percent 1–99 integer; range ∈ {7,30,60}.
- Rate-limit the analytics endpoint per shop (it is expensive).
- Dependency audit (`composer audit`, `npm audit`) clean before delivery.
- Content-Security-Policy `frame-ancestors` set to the shop's admin origins as
  Shopify requires for embedded apps.

---

## 8. Testing and quality bar ("no mistakes")
- Function: unit tests + brute-force optimality check + fixture runs (§5.4).
- Laravel: feature tests for OAuth guard, webhook HMAC, settings save (success,
  Shopify error, read-back mismatch), analytics computation against recorded
  GraphQL fixtures that reproduce every §4.4 row.
- E2E on the development store, driven through the built-in browser: create the
  five sample products at the §4.4 prices (plus one with a compare-at markdown),
  run each cart scenario through to the checkout page, and capture a screenshot
  of the cart totals for each. Then open the embedded page and screenshot the
  settings block and the analytics block showing those test orders.
- Deliverable: `docs/qa/` with the screenshots, a scenario → expected → observed
  table, and the test-run output. Anything not verified is listed as such.

---

## 9. Deployment
- Cloudways: PHP app, Hybrid stack, web root = `web/public` (or the Laravel
  package's convention), MySQL database for the package's shop/session tables,
  queue not required (analytics is synchronous with the cache).
- `shopify app deploy` publishes the Function and the app configuration; the
  deploy guide records the exact commands, the `.env` keys, where the app URL is
  set, and how to roll back a Function version.
- Install on the live Le Voile store **only after the owner says so**, and with the
  switch **off** by default; the owner turns it on from the page.

---

## 10. Risks and how the plan handles them
1. **Partial-quantity targeting in the Discount Function API.** If a line of
   quantity 3 must get 1 gift + 1 at 30% + 1 full, the Function needs
   `cartLine { id, quantity }` targets or equivalent. Verify in the first spike
   before anything else is built; if impossible, report back with the options
   (e.g. a Cart Transform to split lines) rather than approximating.
2. **Combining with existing coupon codes.** Confirm on the dev store that a code
   entered at checkout is rejected/ignored while the offer is on, and document
   the behaviour the merchant will see.
3. **`read_orders` 60-day window** — ranges capped at 60 days by design.
4. **Laravel Shopify package currency** — confirm maintenance status and session
   token support before adopting; fall back to a minimal hand-rolled OAuth +
   App Bridge JWT verification if needed (small surface, well documented by
   Shopify).
5. **Owner sign-in steps.** The CLI login, Partners dev-store creation, and the
   live install each need the owner at the keyboard once. The plan marks these as
   explicit hand-off points.

---

## 11. Suggested work split (the owner asked for several specialised sessions)
Each session owns a disjoint set of files; none touches another's. Order matters
for the first two.

| # | Session | Owns | Done when |
|---|---|---|---|
| 1 | **Function spike + core** | `extensions/bogo-discount/**`, shared constants | §4.4 table passes in unit + fixture tests; risk #1 resolved |
| 2 | **Laravel shell** | `web/**` except the page UI and analytics service | OAuth, session tokens, webhooks, shop allow-list, `.env.example`, feature tests green |
| 3 | **Embedded page UI** | `web/resources/**`, page controller | Settings block saves with read-back confirmation; RTL; phone width |
| 4 | **Analytics service** | `web/app/Services/Analytics*`, its tests | Five tiles + table from GraphQL fixtures; cache; cap |
| 5 | **Security review** | read-only, then a fixes PR | §7 checklist all ticked with evidence |
| 6 | **QA / E2E** | `docs/qa/**`, dev-store data | Screenshots for every scenario; observed = expected |
| 7 | **Deploy guide** | `docs/deploy.md` | Someone else could deploy from it on Cloudways |

Sessions 3 and 4 can run in parallel after 2. Session 5 runs after 1–4. Session 6
runs last, against the deployed dev build.

---

## 12. Decisions made by the designer (not by the owner) — flag if wrong
- Laravel for the embedded page (hosting fit); JavaScript for the Function.
- Settings in an app-owned shop metafield; no app database beyond the package's
  shop table.
- Analytics computed live from Admin GraphQL, cached 10 min, capped at 2,000
  orders, ranges 7/30/60.
- Discount title «اشتري واحدة وخد واحدة»; line messages «هدية» / «خصم N%».
- Sorting/pairing by `P_now` (money-optimal), `P_full` only for the single unit's
  target price; tie-breaking rules in §4.2; half-up rounding to piastres.
- Scenario-2 tile definition (even → 0, odd → least-generous single unit).
- Profit tile optional; A/B test deferred; CRM integration dropped.
- Shop allow-list so no third store can install the custom app.

## 13. Left to the builder
- Exact Shopify API version and Function API shape (follow what the CLI scaffolds
  today; verify §5.2).
- Choice of Laravel Shopify package vs minimal hand-rolled auth (§10.4).
- Polaris integration approach inside Blade.
- Test runners and fixture format.
- Internal naming, folder layout inside `web/`, CI (none required).
