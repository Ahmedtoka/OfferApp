# Le Voile BOGO Offer App — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A custom Shopify app that applies "buy one get one free, or one piece alone at 30% off" automatically in cart and checkout via a Discount Function, plus one embedded Arabic admin page (on/off, percent, five live analytics numbers).

**Architecture:** One git repo with three parts. `extensions/bogo-discount/` is a JavaScript Discount Function whose pure algorithm (`bogo.js`) is tested against a shared fixture file and a brute-force optimality check. `web/` is a Laravel 12 app that authenticates the embedded page with App Bridge session tokens (JWT) and Shopify **token exchange** (no OAuth redirect; see Decisions), writes the shop metafield that configures the Function, owns the single automatic discount, receives webhooks, and computes analytics live from Admin GraphQL. `shared/` holds the message/title constants and the acceptance fixtures that both the JS and PHP tests consume, so the two implementations of the rule cannot drift.

**Tech Stack:** Shopify CLI 4.9.4, Discount Function API (JS → Wasm via Javy, API version as scaffolded by the CLI), Vitest; PHP 8.2+/Laravel 12, PHPUnit, MySQL; Polaris web components + App Bridge CDN script in Blade (vanilla JS, no bundler); Cloudways PHP Hybrid.

**Spec:** `docs/superpowers/specs/2026-10-30-bogo-offer-app-design.md` — every rule, number and string below is taken from it; section references (§) point there.

## Global Constraints

- Money is computed in integer piastres; output amounts are 2-decimal strings, half-up rounding (§4.3).
- Sorting key is `P_now` ascending, ties by `P_full` ascending, then line id (§4.2 step 2). Odd-case tie: cheapest single by `P_full` (§4.2 step 3).
- Never raise a price: single-unit discount = `max(0, P_now − P_full × (1 − pct/100))` (§4.3).
- Default percent **30**, allowed integer **1–99** (§4.1, §7). Analytics range ∈ **{7, 30, 60}** days, default **30**, never 90 (§3, §6.1).
- Exact strings (contract, §5.2, §5.1): discount title `اشتري واحدة وخد واحدة`, gift message `هدية`, single-unit message `خصم {pct}%` (e.g. `خصم 30%`). Defined once in `shared/offer-constants.json`; nothing else may spell them.
- Metafield: shop-level, namespace `$app:bogo`, key `config`, JSON `{"enabled": bool, "percent": int, "updated_at": ISO8601}` (§5.3).
- Scopes: `write_discounts`, `read_orders`, `read_products` (compare-at prices for scenario 2), nothing else (§3). `read_orders` returns 60 days only.
- The automatic discount: exactly one, `combinesWith` all `false`, `discountClasses: [PRODUCT]` (§5.1, G3).
- Eligibility: all product variants; exclude non-variant merchandise and gift cards (§4.5). Function returns no discounts when `enabled` is false or the metafield is missing/invalid.
- Analytics cache 10 minutes per shop+range; cap 2,000 orders with note `محسوب على أول 2000 أوردر` (§6.2).
- Admin copy (§6.1): toggle `العرض شغال` / `متوقف`; field label `نسبة الخصم على القطعة الفردية`; button `حفظ`; success text `تم الحفظ وتم تحديث الخصم في شوبيفاي`; failures show the Shopify error verbatim in red. Tile labels: `أوردرات أخدت العرض`, `هدايا`, `خصم 30%` (live percent), `إجمالي الخصم`, `السيناريو التاني كان هيدخل زيادة`.
- Secrets only in `web/.env`; `web/.env.example` lists every key (§7). App ownership and host (`Arena` Partners org, `offer.arenahere.com`) live only in `shopify.app.toml` and `.env` — confirmed with the owner before the first `shopify app` command (§3).
- Never install on `levoilestores.myshopify.com` without the owner's explicit go-ahead in chat; first install there is with `enabled: false` (§9).
- The agent never types the owner's Shopify password; the owner signs in in the browser when the CLI asks (§3, §10.5).

## Review Focus

1. **A line with quantity ≥ 3 that must receive both a gift and the single-unit discount** (e.g. `C ×3` with `A ×2`). Expected: exactly 1 unit free, 1 unit at 30%, 1 at full price on that line, totals per §4.4. Test: `mixed_2_and_3` fixture in Task 2 and the spike cart in Task 1; dev-store screenshot in Task 12.
2. **Shop metafield missing, malformed JSON, or `percent` out of range.** Expected: Function returns no discounts (store behaves as if the offer is off), never a crash or a wrong percent. Test: Task 3 `returns no operations when metafield is null/invalid/percent 0`.
3. **Session token with wrong `aud`, expired, or `dest` shop not in the allow-list.** Expected: 401 with an empty JSON body, no token exchange attempted. Test: Task 6 middleware tests.
4. **Metafield write succeeds but the automatic discount cannot be created/read back.** Expected: page shows the Shopify error in red, never the success text. Test: Task 8 `save fails when discount ensure fails`.
5. **An order whose only discount is a coupon code (or another automatic discount).** Expected: not counted as an offer order, contributes nothing to gifts/percent/total. Test: Task 9 `ignores orders with foreign discount allocations`.

---

## File Structure

```
OfferApp/
├── shopify.app.toml                      # app config: name, client_id, scopes, app URL, webhooks (Task 1)
├── package.json                          # workspace root: vitest + shopify cli dev deps (Task 1)
├── .gitignore
├── shared/
│   ├── offer-constants.json              # title, messages, metafield ns/key, default pct (Task 2)
│   └── fixtures/bogo-cases.json          # acceptance table + edge cases, consumed by JS and PHP tests (Task 2)
├── extensions/bogo-discount/
│   ├── shopify.extension.toml            # scaffolded; targets cart.lines.discounts.generate.run (Task 1)
│   ├── src/
│   │   ├── cart_lines_discounts_generate_run.graphql   # input query (Task 3)
│   │   ├── cart_lines_discounts_generate_run.js        # adapter: input → operations (Task 3)
│   │   ├── bogo.js                                      # pure algorithm (Task 2)
│   │   └── money.js                                     # piastre helpers (Task 2)
│   ├── test/
│   │   ├── bogo.test.js                  # fixture table + edge cases (Task 2)
│   │   ├── bogo.bruteforce.test.js       # optimality property test (Task 2)
│   │   ├── run.test.js                   # adapter tests incl. metafield gating (Task 3)
│   │   └── fixtures/*.json               # real Function input JSON per §4.4 row (Task 3)
│   └── package.json
├── web/                                  # Laravel 12
│   ├── app/
│   │   ├── Offer/
│   │   │   ├── BogoCalculator.php        # PHP port of bogo.js, scenario 1 + 2 (Task 4)
│   │   │   ├── OfferConstants.php        # reads shared/offer-constants.json (Task 4)
│   │   │   └── Unit.php, OfferResult.php # value objects (Task 4)
│   │   ├── Shopify/
│   │   │   ├── SessionToken.php          # JWT verify (Task 6)
│   │   │   ├── TokenExchange.php         # session token → offline access token (Task 6)
│   │   │   ├── GraphqlClient.php         # interface (Task 6)
│   │   │   ├── HttpGraphqlClient.php     # Admin GraphQL over Laravel Http (Task 6)
│   │   │   └── WebhookSignature.php      # HMAC check (Task 7)
│   │   ├── Http/Middleware/
│   │   │   ├── VerifySessionToken.php    # Task 6
│   │   │   ├── VerifyWebhookHmac.php     # Task 7
│   │   │   ├── VerifyAdminQueryHmac.php  # page load HMAC (Task 6)
│   │   │   └── EmbeddedCsp.php           # frame-ancestors (Task 6)
│   │   ├── Http/Controllers/
│   │   │   ├── HomeController.php        # GET / (Task 10)
│   │   │   ├── SettingsController.php    # GET/PUT /api/settings (Task 8)
│   │   │   ├── AnalyticsController.php   # GET /api/analytics (Task 9)
│   │   │   └── WebhookController.php     # POST /webhooks (Task 7)
│   │   ├── Models/Shop.php               # Task 5
│   │   └── Services/
│   │       ├── OfferSettings.php         # metafield write + read-back (Task 8)
│   │       ├── AutomaticDiscountManager.php  # ensure the one discount (Task 8)
│   │       ├── Analytics/OrdersFetcher.php   # paginated orders query, cap (Task 9)
│   │       ├── Analytics/OfferAnalytics.php  # tiles + table (Task 9)
│   │       └── Analytics/AnalyticsResult.php # DTO (Task 9)
│   ├── config/shopify.php                # api key/secret/version/allowed shops/app url (Task 5)
│   ├── database/migrations/*_create_shops_table.php (Task 5)
│   ├── resources/views/home.blade.php    # Task 10
│   ├── public/app.js, public/app.css     # Task 10
│   ├── routes/web.php, routes/api.php
│   ├── tests/Feature/*, tests/Unit/*, tests/Fixtures/graphql/*.json
│   └── .env.example
└── docs/
    ├── superpowers/specs/…, superpowers/plans/…
    ├── spike/2026-10-30-partial-quantity-targeting.md   # Task 1
    ├── security/2026-10-30-review.md                     # Task 11
    ├── qa/README.md, qa/screenshots/*.png, qa/test-output/*.txt  # Task 12
    └── deploy.md                                         # Task 13
```

**Decisions made in this plan (flag if wrong):**
- **Auth = session token + token exchange, hand-rolled**, not `kyon147/laravel-shopify`. The spec allows the hand-rolled fallback (§10.4, §13); token exchange is Shopify's current recommended flow for embedded apps, needs no OAuth redirect/callback, and keeps the security surface to two small classes. §7's "HMAC on OAuth callback" is therefore satisfied by (a) JWT signature verification on every request and (b) HMAC verification of the admin query string on page load.
- **Function API target:** the CLI 4.x scaffolds the `cart.lines.discounts.generate.run` target (`cartLinesDiscountsGenerateRun`), whose `productDiscountsAdd` candidates accept `targets: [{ cartLine: { id, quantity } }]`. Task 1 verifies this on a real cart before anything else.
- **Mapping to Shopify output:** one candidate per (line, role): a gift candidate with `fixedAmount.amount = giftQty × P_now` targeting `quantity: giftQty`, and a single candidate with `fixedAmount.amount = singleAmount` targeting `quantity: 1`. `appliesToEachItem: false`. Candidates with amount 0 are not emitted. `selectionStrategy: ALL`.
- **Analytics classification:** an allocation belongs to the offer when its application title equals the gift message or matches `^خصم \d+%$`, or equals the discount title (whichever Shopify records on orders; Task 12 confirms on the dev store and Task 9 handles both).
- Shared constants are a JSON file imported by the Function bundle (esbuild resolves JSON) and read by PHP.
- Optional profit tile (§6.1): **dropped** for this delivery.
- Laravel app lives in `web/`; webroot `web/public`; no queue.

---

### Task 0: Repository baseline

**Files:**
- Create: `.gitignore`
- Already present: `docs/superpowers/specs/2026-10-30-bogo-offer-app-design.md`, this plan

- [ ] **Step 1: Write `.gitignore`** with: `node_modules/`, `web/vendor/`, `web/.env`, `web/storage/*.key`, `web/storage/logs/`, `web/storage/framework/cache/data/`, `extensions/*/dist/`, `.shopify/`, `*.wasm`, `.DS_Store`, `Thumbs.db`. Keep `web/.env.example` tracked.
- [ ] **Step 2: Commit**
```bash
git add .gitignore docs
git commit -m "docs: add approved BOGO offer design and implementation plan"
```

---

### Task 1: Spike — partial-quantity targeting (risk §10.1) and app scaffold

**Hand-off point:** before the first `shopify app` command the owner confirms in chat: Partners organisation (default **Arena**) and app hostname (default **offer.arenahere.com**). The owner signs in to Shopify in the browser when the CLI prompts. The owner must have a **development store** in that organisation (name it in the spike doc).

**Files:**
- Create: `shopify.app.toml` (via CLI), `package.json` (root), `extensions/bogo-discount/**` (scaffold), `docs/spike/2026-10-30-partial-quantity-targeting.md`

**Interfaces:**
- Produces: the extension folder with `shopify.extension.toml` naming the target `cart.lines.discounts.generate.run`, and the generated `schema.graphql`; the app's `client_id`; the dev store domain; the Function's `functionId` (from `shopify app function info` or the deploy output).

- [ ] **Step 1: Scaffold the app and the Function**
Run from `C:\xampp\htdocs\OfferApp`:
```bash
shopify app init --name offer-app --template none --path .
shopify app generate extension --template discount --flavor vanilla-js --name bogo-discount
```
If `init --path .` refuses a non-empty folder, init into `C:\xampp\htdocs\offer-app-tmp` and move `shopify.app.toml`, `package.json`, `extensions/` into the repo. Expected: `extensions/bogo-discount/shopify.extension.toml` exists; `grep -n "target" extensions/bogo-discount/shopify.extension.toml` prints `cart.lines.discounts.generate.run`. Record the `api_version` from the toml in the spike doc.

- [ ] **Step 2: Confirm the output schema allows a quantity on a cart-line target**
Run: `shopify app function schema --path extensions/bogo-discount` then
`grep -n -A3 "CartLineTarget" extensions/bogo-discount/schema.graphql`
Expected: a type with `id: ID!` and `quantity: Int`. If `quantity` is absent → **stop**, write the spike doc with the finding and the options (Cart Transform to split lines; one candidate per line with a combined message), and report to the owner. Do not continue to Task 2.

- [ ] **Step 3: Hand-write a spike Function body**
In `extensions/bogo-discount/src/cart_lines_discounts_generate_run.js` return, for the first cart line only, two candidates: `{message:"هدية", targets:[{cartLine:{id, quantity:1}}], value:{fixedAmount:{amount:<amountPerQuantity>}}}` and `{message:"خصم 30%", targets:[{cartLine:{id, quantity:1}}], value:{fixedAmount:{amount:<0.3 × amountPerQuantity, 2dp>}}}` inside one `productDiscountsAdd` with `selectionStrategy: "ALL"`. Input query: `cart { lines { id quantity cost { amountPerQuantity { amount } } } }` plus `discount { discountClasses }`.

- [ ] **Step 4: Run it locally against a fixture**
Create `extensions/bogo-discount/test/fixtures/spike-qty3.json` with one line, quantity 3, `amountPerQuantity.amount: "900.0"`. Run:
`shopify app function run --path extensions/bogo-discount --input test/fixtures/spike-qty3.json --export cart_lines_discounts_generate_run`
Expected: JSON output with two candidates and no schema validation error.

- [ ] **Step 5: Verify on the development store**
Run `shopify app dev` (owner signs in; select the dev store). In the GraphiQL the CLI opens, run `discountAutomaticAppCreate(automaticAppDiscount:{title:"spike", functionId:"<id>", startsAt:"2026-01-01T00:00:00Z", discountClasses:[PRODUCT], combinesWith:{orderDiscounts:false, productDiscounts:false, shippingDiscounts:false}})`. In the built-in browser add any product with quantity 3 to the dev store cart, open `/cart` and then checkout. Expected on the cart page: the line shows two discount lines, `هدية` −900.00 and `خصم 30%` −270.00, line total 1530.00. Screenshot → `docs/spike/cart-qty3.png`.

- [ ] **Step 6: Write the spike doc** `docs/spike/2026-10-30-partial-quantity-targeting.md`: API version, schema excerpt, fixture output, screenshot, verdict **GO / NO-GO**, dev store domain, `client_id`, `functionId`. Delete the `spike` discount from the dev store Discounts screen afterwards (note that it was deleted).

- [ ] **Step 7: Commit**
```bash
git add shopify.app.toml package.json extensions docs/spike
git commit -m "spike: verify Discount Function cart-line quantity targeting on dev store"
```

---

### Task 2: Pure BOGO algorithm in JavaScript (TDD, shared fixtures, brute force)

**Files:**
- Create: `shared/offer-constants.json`, `shared/fixtures/bogo-cases.json`, `extensions/bogo-discount/src/money.js`, `extensions/bogo-discount/src/bogo.js`, `extensions/bogo-discount/test/bogo.test.js`, `extensions/bogo-discount/test/bogo.bruteforce.test.js`, `extensions/bogo-discount/package.json` (add vitest + `"test": "vitest run"`)

**Interfaces:**
- Produces (`src/money.js`): `toPiastres(decimalString|number): number` (half-up, integer), `toAmountString(piastres: number): string` (e.g. `120000 → "1200.00"`).
- Produces (`src/bogo.js`):
  - `type Line = { id: string, quantity: number, pNow: number, pFull: number }` (piastres)
  - `type Unit = { lineId: string, pNow: number, pFull: number }`
  - `expandUnits(lines: Line[]): Unit[]`
  - `sortUnits(units: Unit[]): Unit[]` — key `pNow`, then `pFull`, then `lineId` (string compare).
  - `singleDiscount(unit: Unit, pct: number): number` — `max(0, pNow − round(pFull × (100 − pct) / 100))`, half-up on the target price.
  - `computeOffer(units: Unit[], pct: number, mode: 'max'|'min' = 'max'): { assignments: Array<{ unit: Unit, role: 'gift'|'single'|'paid', discount: number }>, totalDiscount: number, totalPay: number, singleUnit: Unit|null }` — `mode:'max'` is §4.2; `mode:'min'` is scenario 2 (§6.1 tile 5: odd count → the single candidate with the smallest saving; even → identical to max). Tie-break for both modes: cheapest candidate by `pFull`, then by sorted position.
  - `planLineDiscounts(lines: Line[], pct: number): Array<{ lineId: string, giftQty: number, giftAmount: number, singleQty: 0|1, singleAmount: number }>` — aggregates `computeOffer(...,'max')` per line; omits lines with nothing to discount.
- Produces (`shared/offer-constants.json`):
```json
{ "discountTitle": "اشتري واحدة وخد واحدة", "giftMessage": "هدية",
  "percentMessageTemplate": "خصم {pct}%", "metafieldNamespace": "$app:bogo",
  "metafieldKey": "config", "defaultPercent": 30, "minPercent": 1, "maxPercent": 99,
  "analyticsRanges": [7, 30, 60], "defaultRange": 30, "analyticsOrderCap": 2000,
  "analyticsCacheSeconds": 600 }
```
- Produces (`shared/fixtures/bogo-cases.json`): array of cases, prices in **EGP** (tests convert with `toPiastres`):
```json
{ "name": "abc", "pct": 30,
  "lines": [{"id":"A","quantity":1,"pNow":1200,"pFull":1200}, {"id":"B","quantity":1,"pNow":1100,"pFull":1100}, {"id":"C","quantity":1,"pNow":900,"pFull":900}],
  "expectedPay": 1830, "expectedSaving": 1370, "expectedGifts": {"B": 1}, "expectedSingle": "C",
  "perLine": {"B": {"giftQty":1,"giftAmount":1100,"singleQty":0,"singleAmount":0}, "C": {"giftQty":0,"giftAmount":0,"singleQty":1,"singleAmount":270}},
  "scenario2Pay": 1970 }
```
Cases (values from §4.4 and §6.1): `a` (840/360, single A, s2 840), `ac` (1200/900, gift C), `abc` (above), `abcd` (2100/1900, gifts D,B), `abcde` (2520/2080, gifts D,B, single E, s2 **2770** — scenario 2 picks B as single), `a_x2` (1200/1200, gift A qty1), `a_x3` (2040/1560, gift A qty1, single A, s2 2040), `markdown_single_800` (one line pNow 800 pFull 1000 → pay 700), `markdown_single_650` (pNow 650 pFull 1000 → pay 650, discount 0), `markdown_gift_800` (pNow 800/pFull 1000 paired with pNow 900/pFull 900 → gift is the 800 unit, pay 900), `sort_by_pnow` (pNow 500/pFull 1000 with pNow 900/pFull 900 → gift is the 500 unit, pay 900), `optimality_markdowns` (a=(10,500) b=(500,510) c=(20,520) d=(530,530) → saving 510, pay 550, gifts a and b), `mixed_2_and_3` (line X qty 2 at 1200, line Y qty 3 at 900 → pay 2730, saving 2370; perLine X gift 1/1200; Y gift 1/900 + single 1/270), `empty` (no lines → pay 0).

- [ ] **Step 1: Write `shared/offer-constants.json` and `shared/fixtures/bogo-cases.json`** exactly as above (compute `expectedSaving = Σ(pNow×qty) − expectedPay` for every case; include `scenario2Pay` for `a` (840), `abc` (1970), `abcde` (2770), `a_x3` (2040), `mixed_2_and_3` (**2940**: candidates for the single are C → saving 2370 and A → saving 2160; scenario 2 takes A, pay 5100 − 2160)).

- [ ] **Step 2: Write the failing fixture test** `test/bogo.test.js`:
```js
import cases from '../../../shared/fixtures/bogo-cases.json';
import { expandUnits, computeOffer, planLineDiscounts } from '../src/bogo.js';
import { toPiastres } from '../src/money.js';
const P = (lines) => lines.map(l => ({ ...l, pNow: toPiastres(l.pNow), pFull: toPiastres(l.pFull) }));
describe.each(cases)('$name', (c) => {
  it('pays the expected total and saving', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'max');
    expect(r.totalPay).toBe(toPiastres(c.expectedPay));
    expect(r.totalDiscount).toBe(toPiastres(c.expectedSaving));
  });
  it('gifts and single land on the expected units', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'max');
    const gifts = {}; r.assignments.filter(a => a.role === 'gift').forEach(a => { gifts[a.unit.lineId] = (gifts[a.unit.lineId] ?? 0) + 1; });
    expect(gifts).toEqual(c.expectedGifts ?? {});
    expect(r.singleUnit?.lineId ?? null).toBe(c.expectedSingle ?? null);
  });
  it('per-line plan matches', () => {
    const plan = Object.fromEntries(planLineDiscounts(P(c.lines), c.pct).map(p => [p.lineId, p]));
    for (const [id, exp] of Object.entries(c.perLine ?? {})) {
      expect(plan[id]).toMatchObject({ giftQty: exp.giftQty, giftAmount: toPiastres(exp.giftAmount), singleQty: exp.singleQty, singleAmount: toPiastres(exp.singleAmount) });
    }
  });
  it.runIf(c.scenario2Pay !== undefined)('scenario 2 pays the least-generous total', () => {
    expect(computeOffer(expandUnits(P(c.lines)), c.pct, 'min').totalPay).toBe(toPiastres(c.scenario2Pay));
  });
});
it('sorts by pNow, then pFull, then lineId', () => { /* three units: (900,900,'b'), (900,1000,'a'), (500,1000,'z') → order z, b, a */ });
it('rounds the single target half-up in piastres', () => { /* pNow 100001, pFull 100001, pct 30 → target 70001 (70000.7→70001), discount 30000 */ });
```
Also add a money test: `toPiastres("1200.0") === 120000`, `toPiastres("0.005") === 1`, `toAmountString(27000) === "270.00"`.

- [ ] **Step 3: Run to verify it fails**
Run: `cd extensions/bogo-discount && npx vitest run` — Expected: FAIL, modules not found.

- [ ] **Step 4: Implement `money.js` and `bogo.js`** per the Interfaces. Odd-case search: for each index `i` in the sorted list, remove it, pair the rest adjacently, compute `totalDiscount` with the money rule; keep the best by mode (`max` → largest discount, `min` → smallest), tie → smaller `pFull`, then smaller `i`.

- [ ] **Step 5: Run to verify it passes**
Run: `npx vitest run` — Expected: all green.

- [ ] **Step 6: Write the brute-force property test** `test/bogo.bruteforce.test.js`:
```js
// For every cart of n ≤ 7 units (seeded random prices: pFull in 100..2000 EGP, pNow in 50%..100% of pFull, plus duplicates),
// enumerate: if odd, every choice of single; then every perfect matching of the remaining units;
// gift = unit with lower pNow in each pair (tie: either); saving = Σ gift.pNow + singleDiscount(single).
// assert computeOffer(...,'max').totalDiscount === max over the enumeration, for 300 random carts and for every fixture case.
```
Expected: PASS. If a counter-example appears, **do not change the test**: report it (it means the spec's §4.2 claim fails) and stop.

- [ ] **Step 7: Commit**
```bash
git add shared extensions/bogo-discount/src/bogo.js extensions/bogo-discount/src/money.js extensions/bogo-discount/test extensions/bogo-discount/package.json
git commit -m "feat(function): BOGO algorithm with shared fixtures and brute-force optimality test"
```

---

### Task 3: Function adapter — input query, metafield gating, output candidates, fixture runs

**Files:**
- Modify: `extensions/bogo-discount/src/cart_lines_discounts_generate_run.graphql`, `extensions/bogo-discount/src/cart_lines_discounts_generate_run.js`
- Create: `extensions/bogo-discount/test/run.test.js`, `extensions/bogo-discount/test/fixtures/{a,ac,abc,abcd,abcde,a_x2,a_x3,mixed_2_and_3,disabled,no_metafield,giftcard}.json`

**Interfaces:**
- Consumes: Task 2 `planLineDiscounts`, `toPiastres`, `toAmountString`, constants JSON.
- Produces: `cartLinesDiscountsGenerateRun(input): { operations: Array<{ productDiscountsAdd: { selectionStrategy: 'ALL', candidates: Candidate[] } }> }` where `Candidate = { message: string, targets: [{ cartLine: { id, quantity } }], value: { fixedAmount: { amount: string, appliesToEachItem: false } } }`. Returns `{ operations: [] }` when disabled, when the metafield is missing/invalid, when `percent` ∉ [1,99], when `PRODUCT` ∉ `discount.discountClasses`, or when nothing is discountable.
- Input query:
```graphql
query Input { cart { lines { id quantity cost { amountPerQuantity { amount } compareAtAmountPerQuantity { amount } }
  merchandise { __typename ... on ProductVariant { id product { id isGiftCard } } } } }
  discount { discountClasses }
  shop { metafield(namespace: "$app:bogo", key: "config") { value } } }
```
- `pFull = compareAt > amount ? compareAt : amount` (§4.1).

- [ ] **Step 1: Write failing adapter tests** `test/run.test.js` — build input objects from each fixture case through a helper `inputFor(case, {enabled=true, percent=30})`; assert: candidate amounts and messages (`هدية`, `خصم 30%`), quantities (`abc` → gift on B qty 1 amount "1100.00", single on C qty 1 amount "270.00"; `mixed_2_and_3` → Y: gift qty 1 "900.00" + single qty 1 "270.00", X: gift qty 1 "1200.00"); `percent: 40` → message `خصم 40%` and `a` single amount "480.00"; `disabled` / `no_metafield` / `{"enabled":true,"percent":0}` / `"not json"` → `operations: []`; gift-card line excluded; `discountClasses: ["ORDER"]` → `[]`; a line with pNow 650/pFull 1000 alone → `[]` (no zero-amount candidate).
- [ ] **Step 2: Run to verify it fails** — `npx vitest run test/run.test.js` → FAIL.
- [ ] **Step 3: Implement the query and adapter.** Import constants from `../../../shared/offer-constants.json`; message for the single unit = template with `{pct}` replaced.
- [ ] **Step 4: Run to verify it passes** — Expected: PASS.
- [ ] **Step 5: Write real input fixtures** (shape = the generated `schema.graphql` input, including `shop.metafield.value` as a JSON **string**) for every §4.4 row and run each:
`shopify app function run --path extensions/bogo-discount --input test/fixtures/abc.json --export cart_lines_discounts_generate_run`
Expected: output equals the adapter test's expectation; save each output next to its input as `*.expected.json` and add a Vitest test that runs `shopify app function run` via `child_process` when `process.env.SHOPIFY_FUNCTION_RUN=1` and compares.
- [ ] **Step 6: Build**: `shopify app function build --path extensions/bogo-discount` → Expected: Wasm built, no warnings about size.
- [ ] **Step 7: Commit**
```bash
git add extensions/bogo-discount
git commit -m "feat(function): wire BOGO algorithm to Discount Function input/output with metafield gating"
```

---

### Task 4: PHP port of the algorithm (same fixtures)

**Files:**
- Create: `web/` (Laravel 12 skeleton via `composer create-project laravel/laravel web`), `web/app/Offer/Unit.php`, `web/app/Offer/OfferResult.php`, `web/app/Offer/BogoCalculator.php`, `web/app/Offer/OfferConstants.php`, `web/tests/Unit/Offer/BogoCalculatorTest.php`

**Interfaces:**
- Produces: `final class Unit { public function __construct(public string $lineId, public int $pNow, public int $pFull) {} }` (piastres).
- `final class OfferResult { /** @var array<int, array{unit: Unit, role: 'gift'|'single'|'paid', discount: int}> */ public array $assignments; public int $totalDiscount; public int $totalPay; public ?Unit $singleUnit; }`
- `final class BogoCalculator { public static function expandUnits(array $lines): array; /* lines: [{id, quantity, pNow, pFull}] */ public static function compute(array $units, int $pct, string $mode = 'max'): OfferResult; public static function toPiastres(string|float|int $amount): int; }` — identical semantics and tie-breaks to Task 2.
- `final class OfferConstants { public static function get(string $key): mixed; public static function percentMessage(int $pct): string; }` reading `base_path('../shared/offer-constants.json')` (path configurable via `config('shopify.shared_constants_path')`).

- [ ] **Step 1: Create the Laravel app**: `composer create-project laravel/laravel web` (Laravel 12). Remove the default welcome route. `php artisan test` → green.
- [ ] **Step 2: Write the failing test** `BogoCalculatorTest` with a data provider that reads `shared/fixtures/bogo-cases.json` and asserts `totalPay`, `totalDiscount`, gifts per line, single line id, and `scenario2Pay` when present — mirror of Task 2 Step 2.
- [ ] **Step 3: Run** `php artisan test --filter BogoCalculator` → FAIL (class not found).
- [ ] **Step 4: Implement the four classes.**
- [ ] **Step 5: Run** → PASS.
- [ ] **Step 6: Commit**
```bash
git add web
git commit -m "feat(web): Laravel skeleton and PHP port of BOGO calculator verified against shared fixtures"
```

---

### Task 5: Shop model, config, `.env.example`

**Files:**
- Create: `web/config/shopify.php`, `web/database/migrations/2026_10_30_000001_create_shops_table.php`, `web/app/Models/Shop.php`, `web/.env.example`, `web/tests/Unit/ShopTest.php`

**Interfaces:**
- `config('shopify.api_key')`, `shopify.api_secret`, `shopify.api_version` (string, same as the extension toml), `shopify.app_url`, `shopify.allowed_shops` (array from comma-separated env `SHOPIFY_ALLOWED_SHOPS`), `shopify.shared_constants_path`.
- `Shop` model: table `shops` — `id`, `shop_domain` (unique), `access_token` (text, cast `encrypted`), `scopes` (string), `installed_at`, `uninstalled_at` (nullable), timestamps. `Shop::isAllowed(string $domain): bool` (static, case-insensitive against config). `Shop::active(string $domain): ?Shop` (installed and not uninstalled).

- [ ] **Step 1: Write failing tests**: `isAllowed('levoilestores.myshopify.com')` true when configured; `isAllowed('evil.myshopify.com')` false; token round-trips encrypted (raw DB column ≠ plain token).
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** config, migration, model. `.env.example` keys: `APP_NAME, APP_ENV, APP_KEY, APP_DEBUG=false, APP_URL=https://offer.arenahere.com, DB_*, CACHE_STORE=database, SHOPIFY_API_KEY, SHOPIFY_API_SECRET, SHOPIFY_API_VERSION, SHOPIFY_ALLOWED_SHOPS=levoilestores.myshopify.com,<dev-store>.myshopify.com, SHOPIFY_SHARED_CONSTANTS_PATH=../shared/offer-constants.json`. `phpunit.xml` uses sqlite `:memory:` and `CACHE_STORE=array`.
- [ ] **Step 4: Run** → PASS. **Step 5: Commit** `feat(web): shop model, shopify config, env example`.

---

### Task 6: Session-token auth, token exchange, GraphQL client, CSP, page-load HMAC

**Files:**
- Create: `web/app/Shopify/SessionToken.php`, `web/app/Shopify/TokenExchange.php`, `web/app/Shopify/GraphqlClient.php`, `web/app/Shopify/HttpGraphqlClient.php`, `web/app/Http/Middleware/VerifySessionToken.php`, `web/app/Http/Middleware/VerifyAdminQueryHmac.php`, `web/app/Http/Middleware/EmbeddedCsp.php`, tests `web/tests/Unit/Shopify/SessionTokenTest.php`, `web/tests/Feature/AuthMiddlewareTest.php`
- Modify: `web/bootstrap/app.php` (register middleware aliases `shopify.session`, `shopify.hmac`, global `EmbeddedCsp`), `web/routes/api.php` (group `middleware('shopify.session')`, prefix `/api`, no CSRF/cookies)

**Interfaces:**
- `SessionToken::verify(string $jwt, string $apiKey, string $apiSecret, array $allowedShops, int $now): array{shop: string, sub: string}` — HS256; checks `exp > now`, `nbf <= now`, `aud === apiKey`, `iss` host equals `dest` host, `dest` host ∈ allowedShops. Throws `InvalidSessionToken` otherwise. No external JWT library (base64url + `hash_hmac`, `hash_equals`).
- `TokenExchange::offlineToken(string $shop, string $sessionJwt): array{access_token: string, scope: string}` — POST `https://{shop}/admin/oauth/access_token` with `client_id, client_secret, grant_type=urn:ietf:params:oauth:grant-type:token-exchange, subject_token, subject_token_type=urn:ietf:params:oauth:token-type:id_token, requested_token_type=urn:shopify:params:oauth:token-type:offline-access-token`. Uses `Http::` facade (fakeable).
- `interface GraphqlClient { public function query(string $shop, string $query, array $variables = []): array; }` — returns the decoded `data`; throws `ShopifyGraphqlException` (with `userErrors`/`errors` text) on HTTP ≠ 200 or top-level `errors`.
- `VerifySessionToken`: reads `Authorization: Bearer`, verifies, loads `Shop::active($shop)`; if none → exchanges the token and creates the Shop (install); sets `$request->attributes->set('shop', $shop)`. Failure → `401 {}`.
- `VerifyAdminQueryHmac`: for `GET /`: verifies `hmac` over the sorted query string (excluding `hmac`) with the API secret and that `shop` ∈ allow-list; failure → 401.
- `EmbeddedCsp`: header `Content-Security-Policy: frame-ancestors https://{shop} https://admin.shopify.com;` when `shop` is known, else `frame-ancestors 'none'`.

- [ ] **Step 1: Write failing tests**: valid token passes and returns shop; wrong `aud` / expired / wrong signature / `dest` not allowed → exception; middleware returns 401 with body `{}` for a missing or bad bearer; a valid token for an uninstalled allowed shop triggers exactly one `Http::fake` call to `/admin/oauth/access_token` and creates the Shop row; `GET /?shop=…&hmac=bad` → 401; `GET /` with a correct HMAC → 200 and the CSP header contains the shop host; `HttpGraphqlClient` throws on `userErrors` and `errors`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Commit** `feat(web): session-token auth with token exchange, admin HMAC, CSP, GraphQL client`.

---

### Task 7: Webhooks (uninstall + compliance)

**Files:**
- Create: `web/app/Shopify/WebhookSignature.php`, `web/app/Http/Middleware/VerifyWebhookHmac.php`, `web/app/Http/Controllers/WebhookController.php`, `web/tests/Feature/WebhookTest.php`
- Modify: `web/routes/web.php` (`POST /webhooks` excluded from CSRF, middleware `shopify.webhook`), `shopify.app.toml` (`[webhooks] api_version` + subscriptions for `app/uninstalled`, `customers/data_request`, `customers/redact`, `shop/redact` with `uri = "/webhooks"`)

**Interfaces:**
- `WebhookSignature::valid(string $rawBody, string $headerHmac, string $secret): bool` — base64 of `hash_hmac('sha256', body, secret, true)`, `hash_equals`.
- `WebhookController::handle(Request)`: by `X-Shopify-Topic`: `app/uninstalled` → set `uninstalled_at`, null the token; compliance topics → `Log::info`; unknown → 200. Always 200 `{}` after a valid HMAC.

- [ ] **Step 1: Write failing tests**: bad HMAC → 401 empty body, no DB change; valid `app/uninstalled` → shop marked uninstalled and token nulled; the three compliance topics → 200 and a log line each.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** `feat(web): HMAC-verified uninstall and compliance webhooks`.

---

### Task 8: Settings service — metafield write/read-back and the single automatic discount

**Files:**
- Create: `web/app/Services/OfferSettings.php`, `web/app/Services/AutomaticDiscountManager.php`, `web/app/Http/Controllers/SettingsController.php`, `web/app/Http/Requests/SaveSettingsRequest.php`, `web/tests/Feature/SettingsTest.php`, `web/tests/Fixtures/graphql/*.json`

**Interfaces:**
- Consumes: `GraphqlClient`, `OfferConstants`, `Shop`.
- `AutomaticDiscountManager::ensure(string $shop): array{id: string, status: string, adminUrl: string}` — (1) `shopifyFunctions(first: 25)` → the one whose `app.title`/`apiType == "discount"` and `title == "bogo-discount"`; (2) `automaticDiscountNodes(first: 5, query: "title:'<discountTitle>'")` → if found, return it; else `discountAutomaticAppCreate` with title = `discountTitle`, `functionId`, `startsAt` = now, `discountClasses: [PRODUCT]`, `combinesWith` all false; throws `ShopifyGraphqlException` on `userErrors`. `adminUrl = https://admin.shopify.com/store/{handle}/discounts/{numericId}`.
- `OfferSettings::read(string $shop): array{enabled: bool, percent: int, updated_at: ?string}` — shop metafield; defaults `{false, 30, null}` when absent.
- `OfferSettings::save(string $shop, bool $enabled, int $percent): array{settings: array, discount: array}` — `metafieldsSet` (ownerId = shop gid, type `json`), then **read back** and compare `enabled`/`percent`; mismatch → throws `SaveMismatch("read-back mismatch")`; then `ensure()`. Any throw → controller returns `422 {"error": "<message verbatim>"}`.
- Routes: `GET /api/settings` → `{settings, discount}`; `PUT /api/settings {enabled: bool, percent: int}` → validated (`percent` integer 1–99 required, `enabled` boolean required) → `{settings, discount, message: "تم الحفظ وتم تحديث الخصم في شوبيفاي"}`.

- [ ] **Step 1: Write failing tests** with a fake `GraphqlClient` bound in the container that replays `tests/Fixtures/graphql/*.json` and records calls: success path returns the message and calls `metafieldsSet` → metafield read → `shopifyFunctions` → `automaticDiscountNodes`; first save creates the discount, second save does **not** call `discountAutomaticAppCreate`; `metafieldsSet` userError → 422 with that text and no discount call; read-back returns `percent: 25` after writing 30 → 422 `read-back mismatch`; discount create userError → 422 with that text; `percent: 0`, `100`, `"abc"`, missing `enabled` → 422 validation.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS. **Step 5: Commit** `feat(web): settings save with metafield read-back and single automatic discount`.

---

### Task 9: Analytics service (live GraphQL, cache, cap, scenario 2)

**Files:**
- Create: `web/app/Services/Analytics/OrdersFetcher.php`, `web/app/Services/Analytics/OfferAnalytics.php`, `web/app/Services/Analytics/AnalyticsResult.php`, `web/app/Http/Controllers/AnalyticsController.php`, `web/tests/Feature/AnalyticsTest.php`, `web/tests/Fixtures/graphql/orders_*.json`
- Modify: `web/routes/api.php` (`GET /api/analytics` with `throttle:analytics`), `web/app/Providers/AppServiceProvider.php` (`RateLimiter::for('analytics', fn($r) => Limit::perMinute(10)->by($r->attributes->get('shop')))`)

**Interfaces:**
- Consumes: `GraphqlClient`, `BogoCalculator`, `OfferConstants`.
- `OrdersFetcher::fetch(string $shop, int $rangeDays, int $cap): array{orders: array, capped: bool}` — query `orders(first: 250, after: $cursor, query: "created_at:>=<ISO> status:any")` with `lineItems(first: 250) { quantity originalUnitPriceSet { shopMoney { amount } } variant { id compareAtPrice } product { id title } discountAllocations { allocatedAmountSet { shopMoney { amount } } discountApplication { ... on AutomaticDiscountApplication { title } ... on DiscountCodeApplication { code } } } }`, follows `pageInfo` until `hasNextPage` is false or `cap` orders collected.
- `AnalyticsResult` fields: `rangeDays, ordersTotal, offerOrders, offerOrdersPct (1 dp), giftUnits, percentUnits, totalDiscount (EGP, 2 dp), scenario2Extra (EGP), topGifts: list<{title, units, value}> (max 10, by value desc), capped: bool, computedAt`.
- `OfferAnalytics::compute(string $shop, int $rangeDays): AnalyticsResult` — `Cache::remember("analytics:{$shop}:{$rangeDays}", 600, …)`. Per order: an allocation is **ours** when its application `title` equals `giftMessage`, or matches `/^خصم \d+%$/u`, or equals `discountTitle`. Offer order = has ≥ 1 ours. `giftUnits += round(alloc / originalUnitPrice)` for gift-titled allocations; `percentUnits += 1` per percent-titled allocation; `totalDiscount += alloc` for ours. Scenario 2: units from line items (`pNow = originalUnitPrice`, `pFull = max(compareAtPrice, pNow)`), `compute(units, pctFromTitle ?? 30, 'min')->totalPay − (Σ originalUnitPrice×qty − Σ ours)` added when positive (never negative; even unit count → 0). If allocation titles turn out to be the discount title only (Task 12 finding), gift/percent units come from `compute(units, pct, 'max')` instead; keep both paths behind `OfferAnalytics::classifyFromMessages(bool)`.
- `GET /api/analytics?range=30` → validated `range` ∈ {7,30,60} (default 30) → JSON of `AnalyticsResult`.

- [ ] **Step 1: Write orders fixtures** reproducing every §4.4 row as an order (titles `هدية` / `خصم 30%`, amounts from the table), plus one order with only a `DiscountCodeApplication`, one with no discounts, and a two-page fixture (`hasNextPage`) to test pagination and the cap (`cap = 3` in a test via config override).
- [ ] **Step 2: Write failing tests**: tiles for the fixture set equal: `offerOrders` 7, `ordersTotal` 9, `offerOrdersPct` 77.8, `giftUnits` 8 (0+1+1+2+2+1+1), `percentUnits` 4 (rows a, abc, abcde, a_x3), `totalDiscount` 9370.00 (360+900+1370+1900+2080+1200+1560), `scenario2Extra` 390.00 (abc 140 + abcde 250; even rows 0), `topGifts` = [B 3 units 3300, A 2 units 2400, D 2 units 1600, C 1 unit 900]; coupon-only order ignored; `range=90` → 422; two calls within 10 minutes hit the client once; 11th request in a minute → 429; capped flag and note when cap hit.
- [ ] **Step 3: Run** → FAIL. **Step 4: Implement.** **Step 5: Run** → PASS. **Step 6: Commit** `feat(web): live offer analytics with cache, cap and scenario-2 recalculation`.

---

### Task 10: Embedded page UI (Arabic, RTL, Polaris web components)

**Files:**
- Create: `web/app/Http/Controllers/HomeController.php`, `web/resources/views/home.blade.php`, `web/public/app.js`, `web/public/app.css`, `web/tests/Feature/HomePageTest.php`
- Modify: `web/routes/web.php` (`GET /` → `HomeController`, middleware `shopify.hmac`)

**Interfaces:**
- Consumes: `GET/PUT /api/settings`, `GET /api/analytics?range=` (Tasks 8–9), `config('shopify.api_key')`.
- Blade: `<html lang="ar" dir="rtl">`, `<meta name="shopify-api-key" content="{{ apiKey }}">`, `<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>`, Polaris web components (`<s-page>`, `<s-section>`, `<s-switch>`, `<s-number-field>`, `<s-button>`, `<s-banner>`, `<s-grid>`/`<s-box>`, `<s-table>`) — verify the exact tag names against the current Polaris web components docs during the task and record them in the file header comment.
- `app.js`: `const token = await shopify.idToken()` before each fetch, `Authorization: Bearer`; on load `GET /api/settings` and `GET /api/analytics?range=30`; save → `PUT`, show success banner with the server `message`, or a critical banner with `error` verbatim; range selector re-fetches; status line `الخصم موجود وشغال في شوبيفاي` + link (`adminUrl`) or `الخصم غير موجود — اضغط حفظ لإنشائه`; capped note; scenario-2 footnote `إعادة حساب على نفس الأوردرات؛ الأوردرات ذات العدد الزوجي تساهم بصفر؛ P_full من سعر المقارنة الحالي`.
- No API token, secret, or shop token ever reaches the browser.

- [ ] **Step 1: Write failing feature test**: `GET /` with a valid HMAC renders `dir="rtl"`, the api-key meta, the five tile labels and the toggle/field/button labels from Global Constraints; response has no occurrence of the API secret.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** Blade + JS + CSS (use `superpowers`'s frontend skill `impeccable` for layout at phone width; grid 1 column < 480px, 2–3 above).
- [ ] **Step 4: Run** → PASS. **Step 5: Manual check** with `shopify app dev` in the built-in browser: open the app in the dev store admin, toggle on, set 30, save → success banner; screenshot `docs/qa/screenshots/admin-settings-saved.png` (QA re-takes it in Task 12).
- [ ] **Step 6: Commit** `feat(web): embedded RTL admin page with settings and analytics blocks`.

---

### Task 11: Security review (§7) and fixes

**Files:**
- Create: `docs/security/2026-10-30-review.md`; fixes in `web/**` as found.

- [ ] **Step 1: Run the `security-review` skill** over `web/` and `extensions/`; for each §7 line record evidence (file:line + test name) in the review doc: HMAC webhooks (Task 7), session token on all `/api/*` (Task 6), no cookie fallback (grep `auth:sanctum|session()` in api routes = none), CSRF (api routes are bearer-only, `POST /webhooks` HMAC — state why CSRF does not apply), secrets (`git grep -n "shpss_\|shpat_\|SHOPIFY_API_SECRET=" -- ':!*.example'` → none), validation (Tasks 8–9 tests), rate limit (Task 9 test), `composer audit` and `npm audit --omit=dev` clean (paste output), CSP header (Task 6 test).
- [ ] **Step 2: Fix every finding** with a test per fix; re-run `php artisan test` and `npx vitest run` → green.
- [ ] **Step 3: Commit** `security: review evidence and fixes`.

---

### Task 12: QA on the development store (built-in browser)

**Hand-off point:** owner is signed in to the dev store admin in the built-in browser.

**Files:**
- Create: `docs/qa/README.md`, `docs/qa/screenshots/*.png`, `docs/qa/test-output/{vitest.txt,phpunit.txt,function-run.txt}`

- [ ] **Step 1: Deploy the Function** `shopify app deploy` (records the version in README); run the Laravel app locally with `shopify app dev` tunnel **or** on the Cloudways dev URL; open the embedded page, turn the offer **on** at 30 and save.
- [ ] **Step 2: Create products** on the dev store: `QA-A 1200`, `QA-B 1100`, `QA-C 900`, `QA-D 800`, `QA-E 600`, and `QA-M` price 800 compare-at 1000. Record handles in README.
- [ ] **Step 3: Run each scenario** (`A`, `A+C`, `A+B+C`, `A+B+C+D`, `A+B+C+D+E`, `A×2`, `A×3`, `A×2 + C×3`, `M` alone, `M + C`): add to cart, open `/cart`, continue to checkout; screenshot the checkout order summary each time → `docs/qa/screenshots/<scenario>-checkout.png`. Fill README's table: scenario · expected pay (§4.4) · observed pay · ✅/❌.
- [ ] **Step 4: Coupon behaviour (§10.2)**: create a 10% code on the dev store, type it at checkout with the offer on; screenshot the message; record what the shopper sees.
- [ ] **Step 5: Place 3 test orders** (Bogus gateway) for `A+B+C`, `A×3`, `A+C`; open the embedded page, range 7 → screenshot settings block and analytics block; record tile values vs expected (offer orders 3, gifts 3, percent 2, total 1370+1560+900 = 3830, scenario-2 extra 140). Note in README how Shopify recorded the allocation titles (message vs discount title) and which classification path Task 9 is using.
- [ ] **Step 6: Phone width**: resize the built-in browser to mobile preset, screenshot the page.
- [ ] **Step 7: Save test output**: `npx vitest run > docs/qa/test-output/vitest.txt`, `php artisan test > docs/qa/test-output/phpunit.txt`, fixture runs → `function-run.txt`.
- [ ] **Step 8: Turn the offer off** on the dev store at the end; write a "Not verified" list in README (anything skipped, e.g. Flutter app, live store).
- [ ] **Step 9: Commit** `qa: dev-store end-to-end evidence`.

---

### Task 13: Deploy guide

**Files:**
- Create: `docs/deploy.md`

- [ ] **Step 1: Write the guide** with these sections, exact commands copied from what Tasks 1/12 actually ran: (1) Cloudways app: PHP, **Hybrid** stack, webroot `public_html` → symlink/point to `web/public`, PHP 8.2+, MySQL db/user; (2) upload code (`git clone` via Cloudways Git deploy or SFTP), `composer install --no-dev --optimize-autoloader`, `.env` from `.env.example` with every key explained, `php artisan key:generate && php artisan migrate --force && php artisan config:cache`; (3) `shopify.app.toml`: `application_url = https://offer.arenahere.com`, redirect URLs, `[webhooks]` URIs, `embedded = true`; `shopify app deploy` and `shopify app config push`-equivalent (CLI 4 term) — record versions; (4) **Pre-launch checklist**: deactivate Le Voile dashboard coupon distribution and existing codes in Shopify Discounts (owner decision 2026-10-30); confirm compare-at prices are set where "original price" matters; (5) **Install on live store only on the owner's explicit go-ahead**: install link from Partners, open the page, confirm it shows `متوقف`, do **not** turn on; (6) rollback: `shopify app versions list` → `shopify app release --version <previous>`; toggle off from the page as the instant kill switch; (7) troubleshooting: 401 on page (HMAC/allow-list), CSP blocked iframe, `read_orders` 60-day limit.
- [ ] **Step 2: Have a fresh reviewer read it** and confirm every command exists in CLI 4.9.4 (`shopify app --help`).
- [ ] **Step 3: Commit** `docs: Cloudways deploy guide`.

---

## Self-review notes

- Spec coverage: §4 → Tasks 2–4; §5 → Tasks 1–3; §6.1 settings → 8, 10; §6.1 analytics → 9, 10; §6.2 → 9; §6.3 → 5–7; §7 → 6, 7, 11; §8 → every task's tests + 12; §9 → 13; §10.1 → 1; §10.2 → 12 step 4; §10.4 → decision above; §11 split → Tasks 1–3 (session 1), 4–7 (2), 10 (3), 9 (4), 11 (5), 12 (6), 13 (7).
- Type consistency: `planLineDiscounts` return shape used by Task 3; `BogoCalculator::compute(…,'min')` used by Task 9; `GraphqlClient::query` used by Tasks 8–9; `AnalyticsResult` field names used by Task 10 JS.
- Proportion: bodies given only where the signature does not determine them (odd-case search, brute force, classification rule).
