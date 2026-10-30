# Delta review: English strings and legacy analytics (e9adb4c..HEAD)

Scope: `docs/reviews/_delta.diff` (fixtures and docs/qa excluded), judged against spec §4–§7 with the owner's 2026-10-30 language override (everything English) and the previous final review.
Focused check run: `php artisan test --filter "AnalyticsTest|HomePageTest|SettingsTest"`, 40 passed (272 assertions).

## Summary

The delta is small and mostly clean. `shared/offer-constants.json` is the single live contract. The Function (`cart_lines_discounts_generate_run.js:46,49`), the vitest expectations (they now read the constants file instead of literals), `AutomaticDiscountManager` (title from `discountTitle`) and the analytics regex (built from `percentMessageTemplate`) all derive from it. Outside `LEGACY_TITLES` and the legacy fixtures, no Arabic string remains in `web/app`, `web/resources`, `web/public`, the Function source or the constants. The existing discount is found by Function id, not by title, so on the dev store the first Save renames the Arabic-titled discount instead of creating a second one. The live store is a fresh install, so its discount is created with the English title. The admin CSS already used logical properties (`max-inline-size`, `min-inline-size`), so the move to LTR needs no layout changes. The success string matches between the controller and `SettingsTest`. `app.js` writes banners with `textContent`, and Blade escapes every new string. The tests were translated, not weakened, and the new legacy test reproduces the same worked numbers as the English fixtures.

The delta does not touch I1–I5. `bogo.js`, `OrdersFetcher`, `HttpGraphqlClient` and the reactivation path in `AutomaticDiscountManager` are unchanged, and the Custom distribution step is still in `deploy.md` §6.

One Important issue: the English titles are generic enough to match discounts the app did not create.

## Critical

None.

## Important

**I1. `web/app/Services/Analytics/OfferAnalytics.php:117, 138-158` — analytics counts any discount titled "Gift" or "N% off" as part of the offer, from any discount type, with any N.**
- **What:** `classify()` checks only the allocation's title string. It ignores `discountApplication.__typename`, which the fetcher already returns. So a `ManualDiscountApplication` (a staff discount on a draft or POS order with reason "Gift"), or a native Shopify automatic discount titled "10% off", is classified as `gift` or `percent`. `percentFrom()` also returns any `\d+` (0, 100, 250, or PHP_INT_MAX for very long digit strings). The first such value becomes the order's `pct` and can become the `rangePercent` that feeds `countsFromRecompute` and `scenario2Extra` for every title-only order in the range.
- **Why:** The Arabic strings (`هدية`, `خصم N%`) were unlikely to collide by accident. "Gift" and "10% off" are among the most common discount titles and staff reasons there are. On the live store, the 60-day window covers orders from before the install. §5 step 2 deactivates code discounts, which carry no title, so it does not prevent this. A collision would inflate "Orders with the offer", Gifts, the percent pieces and Total discount, and could skew Scenario 2. This is a regression introduced by the language switch.
- **Fix:**
  1. Treat an allocation as ours only when `__typename === 'AutomaticDiscountApplication'`. This rejects manual discounts. Before relying on it, confirm on the dev-store `abc` order in GraphiQL that the Function's allocations come back with that typename. All 42 fixture allocations assume it.
  2. In `percentFrom()`, accept a value only if it is within `[minPercent, maxPercent]`; otherwise return null, so the title is not ours.
  3. Add tests: a manual "Gift" allocation is ignored, an automatic "150% off" is ignored, and an automatic "Gift" from another app is a known residual.
- **Residual risk:** a merchant-created native automatic discount titled exactly "Gift" or "N% off" (N from 1 to 99) would still match. Either note this in `deploy.md` ("do not name other automatic discounts Gift / N% off"), or make the line messages more distinctive (for example "BOGO Gift" / "BOGO 30% off"). Changing the messages changes the contract, so it would need the same fixture refresh and QA as this delta did.

## Minor

**M1. `OfferAnalytics.php:142, 152-166` — matching is case-sensitive. Verify the stored case once.**
- **What:** The gift check is `===` and the percent regex has no `i` flag. QA saw "GIFT" / "30% OFF" at checkout and "Gift" / "30% off" on the cart page. That pattern points to a display-time CSS uppercase in checkout, not to a stored value.
- **Evidence:** The 7-day tiles after the new `abc` order (Orders with the offer 4, Gifts 4, 30% pieces 3, matching the expected values) show that the English order was recognised. Had the stored titles been uppercased, it would have been "not ours" and the count would have been 3.
- **Why it is still worth checking:** the message path and the recompute path give the same numbers on those carts, so the tiles do not show which title form matched.
- **Fix:** In GraphiQL, read `lineItems.discountAllocations.discountApplication { __typename ... on AutomaticDiscountApplication { title } }` on that order. Optionally add the `i` flag to both patterns and use `strcasecmp` for the gift and title checks. Both are free, and they remove the doubt even if Shopify normalises the case later.

**M2. `OfferAnalytics.php:166` — `$` also matches before a trailing newline.**
- **What:** `"30% off\n"` would match. **Fix:** end the pattern with `\z`, or add the `D` modifier.

**M3. `web/app/Http/Controllers/HomeController.php:22-23` and `web/app/Offer/OfferConstants.php:27-30` — the tile label is duplicated, and one helper is now dead.**
- **What:** The tile template `'{pct}% off pieces'` is hardcoded, and the initial label is rebuilt by string concatenation (`$defaultPercent.'% off pieces'`) instead of being derived from that template. The two can drift apart. `OfferConstants::percentMessage()` is now called only from `BogoCalculatorTest.php:130`.
- **Why it is fine to keep as a separate string:** the admin tile label is not the customer contract.
- **Fix:** Declare the label once and use `str_replace` on it for the initial label. Then either drop `percentMessage()`, or have `OfferAnalytics::percentPattern()` and the tests use it so that it has a production caller.

**M4. `OfferAnalytics.php:152, 162-167` — `percentPattern()` rebuilds the regex for every allocation, and `percentFrom()` runs twice for each percent allocation (once in `classify`, once in `analyseOrder`).**
- **Why:** PCRE caches compiled patterns, so the cost is small. **Fix:** memoise the pattern in a static property, and have `classify` return the percent it parsed.

**M5. `docs/deploy.md:3, 255, 258` — the operator steps still name Arabic UI labels.**
- **What:** Line 255 tells the operator to confirm the page shows «متوقف» at the live-install gate. The page now shows "Offer is OFF", and «حفظ» is now "Save".
- **Why:** At the last gate before the owner switches the offer on, the guide should match the screen word for word. **Fix:** replace them with the English labels, in quotes.

**M6. `web/tests/Feature/AnalyticsTest.php:130-155` — legacy coverage is per-fixture only.**
- **What:** No test has Arabic and English orders in the same range. The dev store has exactly that, and it is where `rangePercent` takes the first percent it finds. There is also no unit test for `percentFrom()` bounds or the negative cases in I1. **Fix:** add a mixed-range fixture, or two orders appended to an existing one, along with the I1 negative tests.

## Verdict

**Fix then ship.** I1 is roughly a ten-line change plus tests: filter on the typename and bound the percent. Do the M1 GraphiQL check at the same time. The minors can follow, but M5 is a one-line doc fix worth doing before the live install.
