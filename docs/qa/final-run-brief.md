# Final QA run — English strings (dev store)

Built-in browser pane (`mcp__Claude_Browser__*`), signed in to the dev store admin; storefront visitor password `tushau` if `/password` appears. Store `https://levoilest.myshopify.com` (EGP, `E£` at checkout). Function + Laravel are served live by `shopify app dev` (do not touch the CLI).

Customer-facing strings are now English: discount title **Buy One Get One**, gift label **Gift**, single-unit label **30% off** (or `{pct}% off`). The admin page is English/LTR.

Variant ids: QA-A 1200 `68212280688944`; QA-B 1100 `68212280885552`; QA-C 900 `68212280983856`; QA-D 800 `68212281114928`; QA-E 600 `68212281213232`; QA-F 700 `68212281311536`; QA-G 500 `68212281475376`; QA-H 400 `68212281671984`; QA-I 300 `68212281803056`; QA-J 250 `68212281966896`; QA-K 200 `68212282130736`; QA-L 150 `68212282261808`; QA-M 800/compare-at 1000 `68212282360112`.

Cart building: `/cart/clear` → `/cart/add?id=<variant>&quantity=<qty>` per item → `/cart` (wait 3 s) → `/checkout` (wait 5 s). Read the subtotal + labels from the checkout page text; screenshot (scale 1). Save screenshots under `C:\xampp\htdocs\OfferApp\docs\qa\screenshots\final\` (create it) as `<scenario>-checkout.jpg`; also `<scenario>-cart.jpg` for `abc`, `a_x3`, `mixed_2x1200_3x900`, `qty27`.

## Step 0 — admin page first
Open `https://admin.shopify.com/store/levoilest/apps/offer-app-20`, wait 12 s, screenshot → `final/admin-page.jpg`. Click **Save** once (so the discount title is renamed to "Buy One Get One"); wait 10 s; screenshot the green banner → `final/admin-save.jpg`. Then open `https://admin.shopify.com/store/levoilest/discounts` → screenshot `final/discounts-list.jpg` (expect "Buy One Get One", Active, Automatic).

## Step 1 — storefront scenarios (expected subtotal before shipping)
| scenario | cart | expected | labels |
|---|---|---|---|
| a | A | 840.00 | A: 30% off (−360) |
| ac | A, C | 1200.00 | C: Gift |
| abc | A, B, C | 1830.00 | B: Gift; C: 30% off (630) |
| abcd | A..D | 2100.00 | D, B: Gift |
| abcde | A..E | 2520.00 | D, B: Gift; E: 30% off (420) |
| a_x2 | A×2 | 1200.00 | one A Gift |
| a_x3 | A×3 | 2040.00 | one Gift, one 30% off (840), one full |
| mixed_2x1200_3x900 | A×2, C×3 | 2730.00 | A: 1 Gift; C: 1 Gift + 1 30% off (630) + 1 full |
| m | M | 700.00 | M: 30% off (price 700) |
| mc | M, C | 900.00 | M: Gift |
| n6 | A..F | 2800.00 | — |
| n7 | A..G | 3150.00 | G: 30% off (350) |
| n8 | A..H | 3300.00 | — |
| n9 | A..I | 3510.00 | I: 30% off (210) |
| n10 | A..J | 3600.00 | — |
| n11 | A..K | 3740.00 | K: 30% off (140) |
| n12 | A..L | 3800.00 | — |
| same2_plus3 | C×2, A, B, D | 2660.00 | D: 30% off (560) |
| same2_plus4 | C×2, A, B, D, E | 2900.00 | — |
| same3_plus2 | C×3, A, B | 2730.00 | C: 30% off (630) |
| qty27 | C×27 | 12330.00 | 13 Gift, 1 30% off (630), 13 full |
| coupon | A, B, C + code `QA10` | 1830.00 unchanged | record Shopify's rejection message |

## Step 2 — one test order + analytics
Place ONE test order for `abc` (contact `qa@example.com`, QA Test, 1 Test St, Cairo/Cairo, phone 01000000000, shipping any, Credit card test gateway: number `1`, name `QA Test`, 12/30, 111). Screenshot the thank-you page → `final/order-abc-thankyou.jpg` and note the order number. Then open the app page, choose **7 days**, wait, screenshot → `final/admin-analytics-7d.jpg`; read the tiles (expected: this order + the 3 earlier test orders #1035–#1037 if still within 7 days: Orders with the offer 4, Gifts 4, 30% off pieces 3, Total discount EGP 5,200.00, Scenario 2 extra EGP 280.00; if the cache shows older numbers, say so and try 30 days).

## Step 3 — settings round trip
Set percent 40 → Save → screenshot `final/admin-save-40.jpg`; storefront A alone → 720.00 with "40% off" → `final/storefront-a-40pct.jpg`; set back to 30 → Save. Toggle Offer OFF → Save → `final/admin-toggle-off.jpg`; A+C → 2,100.00 → `final/storefront-offer-off.jpg`; toggle ON → Save → `final/admin-toggle-on.jpg`; A+C → 1,200.00. Mobile preset screenshot of the app page → `final/admin-mobile.jpg`; then desktop preset.

## Deliverable
Create `C:\xampp\htdocs\OfferApp\docs\qa\FINAL.md`: date, strings in effect, table `check | expected | observed | screenshot | ✅/❌` for every item above, order number, and a **Not verified** list. Observed values only from the page. Commit `git add docs/qa && git commit -m "qa: final run with English strings"` (never `git add -A`; retry on `index.lock`). Report back under 15 lines: matched/mismatched counts, mismatches with observed values, order number, FINAL.md path.
