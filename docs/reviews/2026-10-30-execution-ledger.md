# SDD ledger — plan: docs/superpowers/plans/2026-10-30-bogo-offer-app.md

## Preflight scan (2026-10-30)
| Pair / task | Produces vs consumes | Finding |
|---|---|---|
| T2 → T3 | `planLineDiscounts` {lineId,giftQty,giftAmount,singleQty,singleAmount} → adapter candidates | consistent |
| T2 → T4 | shared/fixtures/bogo-cases.json schema → PHP data provider | consistent; PHP must use same half-up rounding |
| T2 → T9 | `compute(...,'min')` semantics → scenario-2 tile | consistent |
| T5 → T6/T7/T8 | Shop model (`access_token` encrypted, isAllowed, active) | consistent |
| T6 → T8/T9 | `GraphqlClient::query(shop, query, vars): array` | consistent |
| T8 → T10 | `/api/settings` JSON {settings, discount, message} | consistent |
| T9 → T10 | `AnalyticsResult` field names | consistent |
| T1 schema | CartLineTarget.quantity exists (verified in generated schema 2026-07) | OK |
| T3 fixture format | plan said hand-made *.expected.json; scaffold ships @shopify/shopify-function-test-helpers with payload{input,output} fixtures | Ruling: use the scaffold's fixture format + default.test.js — same intent, less custom code — costs nothing if wrong |
| Spec §6.1 label `خصم 30%` tile | live percent | T10 must render percent from settings |

## Rulings
- Ruling: dev store = `levoilest.myshopify.com` (LevoileSt, Arena Here Development org 129021558) — the only Le Voile-named dev store not used by another project; `levoileshop` was last used by District V — costs: re-run QA on another store if the owner prefers.
- Ruling: Partners org = 129021558 "Arena Here Development" (the Dev Dashboard org the CLI session belongs to) — matches the owner's "Arena" default — costs: re-create the app under another org (client_id changes).
- Ruling: extension removes the scaffold's `cart.delivery-options` target (spec G3: no shipping discounts) — costs: none.
- Ruling: added scenarios requested by owner (carts of 3..12 units, 2-of-same + 3 + 4, 3 + 2) go into shared fixtures (brute-force verified) and QA — costs: longer QA.

## Progress
Task 1: in progress — scaffold committed (8161707), local spike run OK (qty-3 line → two candidates, quantity 1 each), spike discount created on levoilest (gid://shopify/DiscountAutomaticNode/1633016152368), functionId 01a189fa-391a-7bbd-bfd2-c21d3e57a430; dev-store cart verification pending.
Task 2: minor (deferred): bogo.js odd-case search is O(n²) — fine for carts; watch Function instruction budget in Task 3.
Task 2: minor (deferred): no explicit tie-break test for odd case; min==max for even carts only indirect.
Task 2: minor (deferred): perLine test doesn't assert absence of unlisted lines; half-up test misses the .5 boundary; toPiastres NaN on exponent notation.
Task 2: complete (commits 8161707..9a033a8, review clean)
Task 1: complete (spike GO; commits 8161707..HEAD docs/spike; cart+checkout screenshots) — Ruling: spike discount renamed to the real title instead of deleted, so it is the single discount the app's ensure() finds — costs nothing; QA can delete/recreate.
Ruling: Tasks 3 and 4 run in parallel (disjoint files: extensions/ vs web/), per spec §11; implementers told to `git add` only their files and retry on index.lock — costs: a rare interleaved commit, recoverable.
Task 3: minor (deferred): tests/run.test.js spells 'هدية'/'خصم 30%' literally instead of reading constants; money helper convoluted; perLine expected amounts built with production toAmountString.
Task 3: ⚠️ resolved by controller: index.js exports only the cart-lines target and shopify.extension.toml has only that target (done in scaffold commit 8161707, verified).
Task 3: complete (commits 7a35d5f..e6eac9d, review clean)
Ruling: `write_products` added to scopes TEMPORARILY for dev-store QA data seeding (the app cannot create products otherwise and the owner is asleep); must be removed from shopify.app.toml before the final deploy and the removal verified in the deploy guide — costs: forgetting it would over-scope the live install (deploy guide + final review check it).
Task 4: minor (deferred): BogoCalculatorTest expected values pass through toPiastres; no non-empty-fixture assertion; OfferConstants unknown key → warning not error; strcmp vs UTF-16 (ASCII ids only).
Task 4: complete (commits 6dc25d6..9cd186d, review clean)
QA data: 13 products created on levoilest (qa-a..qa-l at 1200,1100,900,800,600,700,500,400,300,250,200,150; qa-m 800 compare-at 1000); variant ids in scratchpad seed output (QA-A 68212280688944, QA-B 68212280885552, QA-C 68212280983856, QA-D 68212281114928, QA-E 68212281213232, QA-F 68212281311536, QA-G 68212281475376, QA-H 68212281671984, QA-I 68212281803056, QA-J 68212281966896, QA-K 68212282130736, QA-L 68212282261808, QA-M 68212282360112).
Ruling: shopify.app.toml [webhooks] api_version will be set to 2026-07 (same as the Function/Laravel) at the toml cleanup before deploy — costs nothing.
Ruling: Task 12 split — 12a storefront scenarios now (Function is live on the dev store), 12b orders+admin page after Task 10 — costs nothing. QA10 code (10%, combinesWith all false) created on the dev store for §10.2.
Task 5: minor (deferred): .env.example DB keys commented (sqlite default) — deploy guide must say to set MySQL keys; no trailing-slash test.
Task 5: complete (commits 9cd186d..d445fa1, review clean)
Ruling: write_publications also added TEMPORARILY (publish QA products to Online Store); removed with write_products before deploy.
Task 6: implementer DONE (b953822); reviewer (opus) dispatched. Note: GET / placeholder route added by Task 6 — Task 10 replaces it.
QA: 13 QA products published to Online Store (publication gid://shopify/Publication/226896642352); QA agent resumed for 12a.
Dev loop: `shopify app dev` now also serves web/ via web/shopify.web.toml + dev-server.cjs behind the CLI tunnel (application_url auto-updated while dev runs).
Dev tunnel (this session): https://york-bye-become-posts.trycloudflare.com → 127.0.0.1:64777 (Laravel via app dev); /api/ping → 401 {} with CSP header verified.
Task 6: review Needs changes — Important: `signature` not excluded from admin HMAC canonical string; minors: install race, ConnectionException unwrapped, 401 body asserts, RS256/string-exp tests. Fix round 1/5 dispatched (resume implementer).
Task 6: fix round 1/5 (5 addressed claimed, 0 open; commits b953822..78ce25d) — scoped re-review dispatched.
Task 7: implementer DONE (6e7539e); reviewer dispatched. Note: added migration making shops.access_token nullable (ruling: accepted — uninstall must null the token).
Task 7: minor (deferred): nullable-token migration shares timestamp 000001 with create_shops (alphabetical tie-break works); down() fails with null tokens.
Task 7: ⚠️ resolved by controller: Task 6 reinstall path uses updateOrCreate and resets uninstalled_at (Task 6 reviewer confirmed).
Task 7: complete (commits 78ce25d..6e7539e, review clean)
Task 6: fix round 1/5 (5 addressed, 0 open; commits b953822..78ce25d)
Task 6: complete (commits d445fa1..78ce25d, review clean after 1 fix round)
QA 12a: complete (dd4e117) — 21/21 scenarios matched on levoilest; coupon QA10 rejected with 'couldn't be used with your existing discounts'.
Task 8: implementer DONE (6d6beb0); reviewer + Task 9 implementer dispatched.
Task 8: minor (deferred): discountNodes(first:50) unpaginated; Function matched by apiType+title not app; read() casts tolerate type drift; strict JSON bool/int validation; missing tests for update-path userErrors / GET with missing Function. Real Admin API shapes verified in QA 12b.
Task 8: complete (commits 6e7539e..6d6beb0, review clean)
Task 9: implementer DONE (92f9d3a); reviewer dispatched.
Task 9: minor (deferred): lineItems(first:250) unpaginated; upstream exception message → 422 (security review to judge leak); mixed title-only/message allocations count in total but not units; no cache stampede lock; empty product title grouping.
Task 9: complete (commits 6d6beb0..92f9d3a, review clean)
Task 10: implementer DONE_WITH_CONCERNS (198dcfc; 4 extra strings — ruling: accepted, they cover states the spec omits); reviewer dispatched; controller doing the in-admin check.
Task 10: minor (deferred): unpinned polaris-2.js script; 200 with non-JSON body shows "no orders"; adminUrl href without scheme check; "%" RTL ordering nit.
Task 10: complete (commits 92f9d3a..198dcfc, review clean). Controller in-admin check: page loads in admin iframe, token exchange created the shop row, settings show enabled/30 + discount status line + link, analytics shows empty state.
Task 11 (security review) + QA 12b dispatched in parallel.
Task 13: complete (0852dcb docs/deploy.md; CLI commands verified via --help). Reviewed with the final whole-branch review.
QA 12b: complete (3b52934). Orders #1035 1830 / #1036 2040 / #1037 1200 ✅; save/read-back/validation/toggle/mobile ✅. BLOCKER: analytics → Shopify error "This app is not approved to access the Order object" (protected customer data access not requested in Dev Dashboard); 30-day range showed stale cached empty result. Minor: status line says discount active even when toggle off (it reports the Shopify discount status by design).
Ruling: requested "Protected customer data" access (reasons: Store management, Analytics; no customer fields) in the Partner Dashboard API access requests page for the app — required for the Orders object (analytics); dev stores get it immediately; status Draft (no App Store review needed for a custom app). Distribution method left unselected (custom distribution is chosen at go-live per deploy guide; irreversible) — costs: none if wrong; owner can edit reasons.
QA 12b analytics: ✅ after PCD (3/3/2/3830/140 + table). Committed.
Task 11: DONE_WITH_CONCERNS — fixes dcece35 (percent numeric), 9064e21 (CSP global), d76831d (rate limiter keyed after auth), 05829ac (Function malformed-metafield tests), doc ba6a49c. Reviewer dispatched.
Ruling: `npm audit` 25 high in build-time codegen deps of @shopify/shopify_function (@graphql-tools/utils, lodash) — accepted and documented: they never ship (Wasm bundle excludes them; Laravel runtime has no npm); surfaced to the owner in the final message with the override option — costs: a future CLI upgrade should clear it.
Task 11: complete (commits 198dcfc..ba6a49c, review clean; npm audit row parked by ruling above). Minor (deferred): review doc cites bootstrap/app.php line numbers.
Final config commit e2f0f91 (scopes minimal, webhooks 2026-07). Test outputs captured in docs/qa/test-output.
Final review: 0 Critical, 5 Important (I1 Function O(n²) instruction budget; I2 analytics counts cancelled orders; I3 save reports success with an inactive discount; I4 deploy guide lacks Custom distribution step; I5 no throttle retry in orders fetch), 17 minor. Verdict: fix then ship. ONE fix dispatch (opus) for I1–I5.
Ruling: I2 — exclude cancelled orders (`-status:cancelled` + cancelledAt guard); keep refunded orders counted as granted (spec silent; discount was granted at purchase; footnote it); do NOT exclude test orders (dev-store QA depends on them) — costs: refunded orders slightly overstate cost.
Ruling: I5 — retry THROTTLED/429 up to 3 times with backoff inside HttpGraphqlClient; "store with >250 orders" cannot be exercised here (dev store has ~31 orders) → listed as not verified.
Final fix wave: 783f792 551d42b cfe7fc3 843c8e9 e9adb4c (I1–I5). Scoped re-review dispatched; controller re-verifying I1/I3 on the dev store.
Final re-review: I1–I5 ADDRESSED, no new breakage, verdict ready to merge. Minor (deferred): confirm PCD auto-grant at custom-distribution install; PHP calculator O(n²) (server-side, fine).
