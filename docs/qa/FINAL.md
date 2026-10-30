# FINAL QA run - English customer-facing strings

Date: 2026-10-30. Store: levoilest.myshopify.com (dev). Browser pane, signed in. Screenshots: `docs/qa/screenshots/final/`.

## Strings in effect (observed)
- Discount title (Discounts list + app page header): **Buy One Get One**
- Checkout lines: **GIFT (-E£x)** and **30% OFF (-E£x)** (checkout uppercases the message); cart page shows **Gift** and **30% off**.
- With percent 40: **40% OFF (-E£480.00)**.
- Admin page copy is English / LTR.

## Method note
Cart building used the storefront cart AJAX API (`/cart/clear.js`, `/cart/add.js` with the brief's variant ids and quantities) from the storefront origin, then navigated to `/cart` (cart scenarios) and `/checkout`, instead of typing `/cart/add?id=` URLs. Same storefront cart, same checkout. Values below were read from checkout page text (`innerText`) and the screenshot, not copied from the brief.

## Admin / setup
| check | expected | observed | screenshot | result |
|---|---|---|---|---|
| App page loads, English | English LTR page | Header "Buy One Get One"; "Offer is ON", "Discount on a single piece (%)", "Save"; tiles English | admin-page.jpg | OK |
| Save | green banner | "Saved. The discount in Shopify is up to date." | admin-save.jpg | OK |
| Discounts list | "Buy One Get One", Active, Automatic | "Buy One Get One" / Active / Automatic / BOGO Discount (Le Voile) / used 3. (QA10 code also Active, Amount off order) | discounts-list.jpg | OK |

## Step 1 storefront scenarios (subtotal before shipping)
| scenario | expected | observed subtotal | observed labels | screenshot | result |
|---|---|---|---|---|---|
| a | 840.00; A 30% off (-360) | E£840.00 | QA-A 30% OFF (-E£360.00) | a-checkout.jpg | OK |
| ac | 1200.00; C Gift | E£1,200.00 | QA-C GIFT (-E£900.00); A full | ac-checkout.jpg | OK |
| abc | 1830.00; B Gift; C 30% (630) | E£1,830.00 | C 30% OFF (-E£270.00) = E£630.00; B GIFT (-E£1,100.00); A full | abc-checkout.jpg, abc-cart.jpg | OK |
| abcd | 2100.00; D, B Gift | E£2,100.00 | D GIFT (-800); B GIFT (-1,100); A, C full | abcd-checkout.jpg | OK |
| abcde | 2520.00; D, B Gift; E 30% (420) | E£2,520.00 | E 30% OFF (-180) = E£420.00; D, B GIFT | abcde-checkout.jpg | OK |
| a_x2 | 1200.00; one A Gift | E£1,200.00 | one A GIFT (-E£1,200.00), one A full | a_x2-checkout.jpg | OK |
| a_x3 | 2040.00; Gift + 30% (840) + full | E£2,040.00 | A 30% OFF (-360) = E£840.00; A GIFT (-1,200); A full. Cart page: "30% off", "Gift" | a_x3-checkout.jpg, a_x3-cart.jpg | OK |
| mixed_2x1200_3x900 | 2730.00 | E£2,730.00 | A: 1 GIFT + 1 full; C: 1 GIFT (-900), 1 30% OFF (-270) = 630, 1 full | mixed_2x1200_3x900-checkout.jpg, -cart.jpg | OK |
| m | 700.00; M 30% (price 700) | E£700.00 | QA-M 30% OFF (-E£100.00), E£800 -> E£700 | m-checkout.jpg | OK |
| mc | 900.00; M Gift | E£900.00 | QA-M GIFT (-E£800.00); C full | mc-checkout.jpg | OK |
| n6 | 2800.00 | E£2,800.00 | E, D, B GIFT; no 30% line | n6-checkout.jpg | OK |
| n7 | 3150.00; G 30% (350) | E£3,150.00 | G 30% OFF (-150) = E£350.00; E, D, B GIFT | n7-checkout.jpg | OK |
| n8 | 3300.00 | E£3,300.00 | H, E, D, B GIFT; no 30% | n8-checkout.jpg | OK |
| n9 | 3510.00; I 30% (210) | E£3,510.00 | I 30% OFF (-90) = E£210.00; H, E, D, B GIFT | n9-checkout.jpg | OK |
| n10 | 3600.00 | E£3,600.00 | J, H, E, D, B GIFT; no 30% | n10-checkout.jpg | OK |
| n11 | 3740.00; K 30% (140) | E£3,740.00 | K 30% OFF (-60) = E£140.00; J, H, E, D, B GIFT | n11-checkout.jpg | OK |
| n12 | 3800.00 | E£3,800.00 | L, J, H, E, D, B GIFT; no 30% | n12-checkout.jpg | OK |
| same2_plus3 | 2660.00; D 30% (560) | E£2,660.00 | D 30% OFF (-240) = E£560.00; B GIFT; A, C, C full | same2_plus3-checkout.jpg | OK |
| same2_plus4 | 2900.00 | E£2,900.00 | E, B GIFT; D, A, C, C full | same2_plus4-checkout.jpg | OK |
| same3_plus2 | 2730.00; C 30% (630) | E£2,730.00 | C 30% OFF (-270) = E£630.00; B GIFT; A, C, C full | same3_plus2-checkout.jpg | OK |
| qty27 | 12330.00; 13 Gift, 1 30% (630), 13 full | E£12,330.00 | qty 13 full E£11,700.00; qty 1 30% OFF (-270) = E£630.00; qty 13 GIFT (-E£11,700.00). Cart page: 13 / 1 / 13 | qty27-checkout.jpg, qty27-cart.jpg | OK |
| coupon (A,B,C + QA10) | 1830.00 unchanged; record message | E£1,830.00 unchanged | Shopify message: "QA10 couldn't be used with your existing discounts." | coupon-checkout.jpg | OK |

## Step 2 order + analytics
| check | expected | observed | screenshot | result |
|---|---|---|---|---|
| Test order for abc | placed | Thank-you page, confirmation **#FQNJEV0NP**, total E£1,830.00, same labels as abc. Orders badge in admin went 31 -> 32 (numeric order name not displayed; admin Orders not opened) | order-abc-thankyou.jpg | OK |
| Analytics 7 days | Orders 4, Gifts 4, 30% pieces 3, Total discount EGP 5,200.00, Scenario 2 EGP 280.00 | Orders with the offer 4 (100% of all orders); Gifts 4; 30% off pieces 3; Total discount EGP 5,200.00; Scenario 2 EGP 280.00; top gifted: QA-B 2 / 2,200.00, QA-A 1 / 1,200.00, QA-C 1 / 900.00 | admin-analytics-7d.jpg (+ -top) | OK |

Observation (not in the expected values): the **30 days** period was inconsistent with 7 days right after the order. At first load and again on re-selection it showed Orders 3, Gifts 3, 30% pieces 2, Total EGP 3,830.00, Scenario 2 EGP 140.00 (admin-analytics-30d-stale.jpg, admin-analytics-30d-after-order.jpg), while 7 days and 60 days showed 4 / 4 / 3 / 5,200 / 280. A few minutes later (after the 40% round trip) 30 days showed 4 / 4 / 3 / 5,200.00 / 280.00. Looks like a stale cache on the 30-day range. Also one transient "Could not reach the server, please try again" on selecting 30 days (admin-analytics-30d-error.jpg); the next 7-day and 30-day selections loaded.

## Step 3 settings round trip
| check | expected | observed | screenshot | result |
|---|---|---|---|---|
| Percent 40 -> Save | saved | "Saved. The discount in Shopify is up to date."; tile label became "40% off pieces"; value 40 persisted after reload | admin-save-40.jpg | OK |
| Storefront A alone at 40 | 720.00, "40% off" | E£720.00, "40% OFF (-E£480.00)" | storefront-a-40pct.jpg | OK |
| Back to 30 -> Save | saved | banner "Saved...", field 30 | admin-save-30-restore.jpg | OK |
| Toggle OFF -> Save | offer off | "Offer is OFF" after save (banner text identical to the previous save, so the banner alone does not prove this save; reload showed OFF, and storefront confirmed). Text still reads "Discount exists and is active in Shopify" while OFF | admin-toggle-off.jpg | OK |
| A+C with offer OFF | 2,100.00 | E£2,100.00, no discount lines | storefront-offer-off.jpg | OK |
| Toggle ON -> Save | offer on | "Offer is ON", banner "Saved. The discount in Shopify is up to date." | admin-toggle-on.jpg | OK |
| A+C with offer ON | 1,200.00 | E£1,200.00; C GIFT (-E£900.00) | storefront-offer-on.jpg | OK |
| Mobile preset (375x812) | page usable | Settings card and tiles stack in one column, readable; desktop preset restored afterwards | admin-mobile.jpg | OK |

## Totals
35 checks (3 admin/setup, 22 storefront, 2 order/analytics, 8 settings). Matched expected: 35. Mismatched vs expected: 0.
Observations: 30-day analytics stale and inconsistent with 7-day; one transient server error on period change; "active in Shopify" text shown while Offer is OFF.

## Not verified
- Numeric Shopify order name (e.g. #1038): the thank-you page shows only confirmation #FQNJEV0NP; admin Orders not opened per rules. Orders badge 31 -> 32 only.
- Cart page screenshots were taken only for abc, a_x3, mixed_2x1200_3x900, qty27 (as in the brief).
- Cause of the 7-day vs 30-day discrepancy (cache TTL vs query window): no CLI/code access in this run.
- Cart built via AJAX API rather than `/cart/add?id=` URLs (equivalent cart, not the literal URL path).

## FallOffer tag eligibility — dev store check (2026-10-30, controller)
Products tagged `FallOffer` (12 of 22): Band Collar Extension 1150, Basic Turkish Clips 1500, Aurora Winter Shawl 500, Bright Winter Shawl 700, Body Sleeveless Round Neck 800, Body Sleeveless Normal Neck 950, Bishop Basic Undershirt 250, Bubbly Kimono 220, Braided Cardigan 1700, Autumn Set 1150, Basic Sleeveless Dress 1400, Belly Blouse 400. The other 10 are untagged.

| cart | expected | observed (checkout) | screenshot | result |
|---|---|---|---|---|
| tagged: Turkish Clips 1500, Band Collar 1150, Aurora Winter Shawl 500 + untagged: Aurora Cotton Scarf 2100, Bubble Gum Scarf 1050 | pair (1500,1150) → Band Collar Gift; Shawl 30% off → 350; untagged at full price; subtotal 1500+350+2100+1050 = **5,000**, savings 1,300 | E£5,000.00, TOTAL SAVINGS E£1,300.00; Shawl 30% OFF −150; untagged scarves full price | tag-mixed-cart-checkout.jpg | ✅ |

## Editable eligible tag — dev store check (2026-10-30, controller)
The tag is now set on the app page ("Eligible product tag"). Saving writes it to the discount's own settings, which Shopify passes to the Function.

| step | expected | observed | evidence | result |
|---|---|---|---|---|
| Tag `FallOffer`; cart: Turkish Clips 1500, Band Collar 1150, Aurora Winter Shawl 500 (all FallOffer) + Aurora Cotton Scarf 2100, Bubble Gum Scarf 1050 (not FallOffer) | 5,000 (Band Collar Gift, Shawl 30% off, scarves full) | 5,000; same labels | cart API read | ✅ |
| Tag switched to `WinterOffer` (no product has it), same cart | no discount at all: 6,300 | 6,300, no labels | cart API read | ✅ |
| Tag switched to `PC4` (only Aurora Cotton Scarf 2100 + Animal Print Cotton Scarf 2150); cart: both scarves + Band Collar 1150 + Shawl 500 | scarves pair → Aurora Cotton Scarf Gift; FallOffer items full: 3,800 | 3,800; Aurora Cotton Scarf Gift | cart API read; admin-tag-PC4.jpg | ✅ |
| Tag set back to `FallOffer` from the page, Save | green banner, note shows "FallOffer" | "Saved. The discount in Shopify is up to date." | admin-tag-saved-FallOffer.jpg | ✅ |
| Same 5-item cart again | 5,000 | 5,000 | cart API read | ✅ |
| Analytics, orders whose products were deleted | scenario 2 extra 280 (was wrongly 5,200) | EGP 280.00 | admin-analytics-scenario2-fixed.jpg | ✅ |

Also fixed this round: the session-token check now tolerates 10 s of clock difference (this machine runs ~0.5 s behind Shopify), the page retries a failed load once before showing an error, and gifts of deleted products are labelled "Deleted product".
