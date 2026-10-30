# QA - Storefront scenarios (2026-10-30)

Store: levoilest.myshopify.com (EGP, shown E£ at checkout). Function version: dev preview via shopify app dev.
Method: carts built via /cart/clear and /cart/add, then /checkout in the built-in browser. Observed subtotal and labels were read from the checkout order-summary DOM text (plus screenshot). No contact/address fields were filled and no order was placed. Screenshots in `docs/qa/screenshots/`.

| scenario | cart | expected subtotal | observed subtotal | labels observed | screenshot | result |
|---|---|---|---|---|---|---|
| a | A x1 | 840.00 | 840.00 | A: خصم 30% (-360), E£840 | a-checkout.jpg | ✅ |
| ac | A, C | 1200.00 | 1,200.00 | C: هدية (-900) | ac-checkout.jpg | ✅ |
| abc | A, B, C | 1830.00 | 1,830.00 | B: هدية (-1,100); C: خصم 30% (-270, price 630); A full | abc-checkout.jpg (+ abc-cart.jpg) | ✅ |
| abcd | A, B, C, D | 2100.00 | 2,100.00 | D, B: هدية; A, C full | abcd-checkout.jpg | ✅ |
| abcde | A..E | 2520.00 | 2,520.00 | D, B: هدية; E: خصم 30% (price 420) | abcde-checkout.jpg | ✅ |
| a_x2 | A x2 | 1200.00 | 1,200.00 | one A هدية, one A full | a_x2-checkout.jpg | ✅ |
| a_x3 | A x3 | 2040.00 | 2,040.00 | one A هدية, one A خصم 30% (840), one A full | a_x3-checkout.jpg (+ a_x3-cart.jpg) | ✅ |
| mixed_2x1200_3x900 | A x2, C x3 | 2730.00 | 2,730.00 | A: 1 هدية + 1 full; C: 1 هدية + 1 خصم 30% (630) + 1 full | mixed_2x1200_3x900-checkout.jpg (+ -cart.jpg) | ✅ |
| m | M x1 (800, compare-at 1000) | 700.00 | 700.00 | M: خصم 30% (-100, price 700) | m-checkout.jpg | ✅ |
| mc | M, C | 900.00 | 900.00 | M: هدية (-800) | mc-checkout.jpg | ✅ |
| n6 | A..F | 2800.00 | 2,800.00 | gifts: B, D, E | n6-checkout.jpg | ✅ |
| n7 | A..G | 3150.00 | 3,150.00 | G: خصم 30% (price 350); gifts B, D, E | n7-checkout.jpg | ✅ |
| n8 | A..H | 3300.00 | 3,300.00 | gifts B, D, E, H | n8-checkout.jpg | ✅ |
| n9 | A..I | 3510.00 | 3,510.00 | I: خصم 30% (price 210); gifts B, D, E, H | n9-checkout.jpg | ✅ |
| n10 | A..J | 3600.00 | 3,600.00 | gifts B, D, E, H, J | n10-checkout.jpg | ✅ |
| n11 | A..K | 3740.00 | 3,740.00 | K: خصم 30% (price 140); gifts B, D, E, H, J | n11-checkout.jpg | ✅ |
| n12 | A..L | 3800.00 | 3,800.00 | gifts B, D, E, H, J, L | n12-checkout.jpg | ✅ |
| same2_plus3 | C x2, A, B, D | 2660.00 | 2,660.00 | D: خصم 30% (price 560); B: هدية; one C هدية | same2_plus3-checkout.jpg | ✅ |
| same2_plus4 | C x2, A, B, D, E | 2900.00 | 2,900.00 | E, B: هدية; one C هدية | same2_plus4-checkout.jpg | ✅ |
| same3_plus2 | C x3, A, B | 2730.00 | 2,730.00 | one C خصم 30% (price 630); one C هدية; B: هدية | same3_plus2-checkout.jpg | ✅ |
| coupon | A, B, C + code QA10 | 1830.00 unchanged | 1,830.00 | unchanged; Shopify message: "QA10 couldn't be used with your existing discounts." | coupon-checkout.jpg | ✅ |

Result: 21 of 21 scenarios matched the expected subtotal; no mismatches. Gift choices matched expectations in every cart (the cheaper unit of each adjacent pair, single unpaired unit at 30%).

Note: the 30% label shows the discount amount (e.g. -E£270.00 on QA-C), while the brief's "(630)" is the resulting price.

## Not verified
- Only the Function's output at checkout (dev preview) was verified; the production-deployed Function version, checkout on other devices/locales, and order creation/payment were not tested.
- Cart-page screenshots were taken only for abc, a_x3 and mixed_2x1200_3x900 as specified.

## Part B — orders, admin page, settings (2026-10-30)

Environment: Claude built-in browser pane (~800x671 viewport), dev store levoilest.myshopify.com, Bogus test gateway (card `1`). Observed values below were read from page screenshots only.

### Test orders (all Paid, Online Store, customer "QA Test")
| scenario | order | confirmation | total observed (thank-you + Orders list) | expected | screenshot |
|---|---|---|---|---|---|
| abc (A,B,C) | #1035 | MJVLTLMLI | E£1,830.00 (QA-C 630.00 "خصم 30%", QA-B FREE "هدية", QA-A 1,200.00) | 1,830.00 | order-abc-thankyou.jpg |
| a_x3 (A x3) | #1036 | MH74V1KUF | E£2,040.00 (1,200 + 840 "خصم 30%" + FREE "هدية") | 2,040.00 | order-a_x3-thankyou.jpg |
| ac (A,C) | #1037 | 8YWYIGR9N | E£1,200.00 (QA-C FREE "هدية") | 1,200.00 | order-ac-thankyou.jpg |

Orders list evidence: orders-list.jpg (#1035 8:07 pm, #1036 and #1037 8:08 pm).

### Checks
| check | expected | observed | screenshot | result |
|---|---|---|---|---|
| Page load: toggle | on | "العرض شغال" toggle on | admin-page-loaded.jpg | ✅ |
| Page load: percent | 30 | 30 | admin-page-loaded.jpg | ✅ |
| Page load: status line | discount exists/active | "الخصم موجود وشغال في شوبيفاي" | admin-page-loaded.jpg | ✅ |
| Page load: link «افتح في الخصومات» | present | present | admin-page-loaded.jpg | ✅ |
| Analytics 7 أيام tiles | 3 / 3 / 2 / 3,830.00 / 140.00 + table | no tiles; red banner "Shopify GraphQL errors: This app is not approved to access the Order object. See https://shopify.dev/docs/apps/launch/protected-customer-data for more details." | admin-analytics-7d.jpg | ❌ |
| Analytics 30 يوم (default on load) | same tiles | empty state "مفيش أوردرات في الفترة دي" (shown although 3 orders exist today) | admin-page-loaded.jpg | ❌ |
| Analytics 60 يوم | same tiles | same red GraphQL "not approved to access the Order object" banner | admin-analytics-60d.jpg | ❌ |
| Save 40 | green banner «تم الحفظ وتم تحديث الخصم في شوبيفاي» | green banner with exactly that text, field 40 | admin-save-40.jpg | ✅ |
| Read-back 40 after reload | 40 | 40 (page reloaded, field showed 40) | (reload screenshot, not saved) | ✅ |
| Storefront A alone at 40 | 720.00 label «خصم 40%» | Subtotal/Total E£720.00, line "%40 خصم (-E£480.00)" | storefront-a-40pct.jpg | ✅ |
| Save 30 | green banner | green banner, field 30 | admin-save-30.jpg | ✅ |
| Storefront A alone at 30 | 840.00 | E£840.00, "%30 خصم (-E£360.00)" | storefront-a-30pct-restored.jpg | ✅ |
| Validation 150 + save | «اكتب رقم صحيح من 1 لـ 99» | red field border + inline text "اكتب رقم صحيح من 1 لـ 99"; no banner | admin-save-invalid.jpg | ✅ |
| Toggle off + save | green banner, toggle off | green banner; label changed to «متوقف», toggle off | admin-toggle-off.jpg | ✅ |
| Off persisted after reload | off | «متوقف» toggle off after reload | (not saved) | ✅ |
| Storefront A+C while off | 2,100.00 no discount | Subtotal/Total E£2,100.00, QA-C 900.00 and QA-A 1,200.00, no discount line | storefront-offer-off.jpg | ✅ |
| Toggle on + save | green banner, toggle on | green banner, «العرض شغال» on | admin-toggle-on.jpg | ✅ |
| Storefront A+C while on | 1,200.00 | E£1,200.00, QA-C FREE "هدية (-E£900.00)" | storefront-offer-on.jpg | ✅ |
| Phone width 375x812 | single column, no horizontal scroll | single column, cards stacked, no horizontal scroll visible | admin-mobile.jpg | ✅ |
| Discounts list | one automatic BOGO discount Active | «اشتري واحدة وخد واحدة», Active, Automatic, "BOGO Discount (Le Voile)". Also visible: Active code discount QA10; other automatic discounts are Expired | discounts-list.jpg | ✅ (see note) |

### Raw analytics tile values
No tile values could be read. 7 أيام and 60 يوم returned the protected-customer-data GraphQL error; 30 يوم returned the empty state. The five tiles and the top-gifts table never rendered, so the expected numbers (3 / 3 / 2 / 3,830.00 / 140.00; QA-B 1 / 1,100.00, QA-A 1 / 1,200.00, QA-C 1 / 900.00) are unverified.

### Findings
1. BLOCKER for analytics: the app is not approved for protected customer data (Order object), so the Orders query fails on the dev store. Requires protected-customer-data access (Partner Dashboard > API access) or an analytics query that avoids protected fields. Cache not suspected for 7d/60d (explicit error).
2. The 30 يوم default view shows the "no orders" empty state while the 7d and 60d views error. Inconsistent: either the error is swallowed for that range or a stale cached empty result was served. Not distinguishable from the page.
3. Minor/observation: after switching the toggle off and saving, the status line still reads "الخصم موجود وشغال في شوبيفاي" (also after reload), although the storefront shows no discount. Wording may only mean "discount object exists", but it can mislead.
4. Note: the brief expected only one automatic discount; the list also contains an Active code discount QA10 (not ours).

### Not verified
- Analytics tiles and top-gifts table (blocked by finding 1) and the 10-minute cache behaviour.
- `% من كل الأوردرات` figure.
- Horizontal scroll at phone width checked visually only (cross-origin iframe, no DOM measurement).
- Everything listed as not verified in earlier sections remains so, except the three orders (now placed) and admin settings/toggle flow.

## Part B follow-up — analytics after Protected customer data access (2026-10-30, controller)

Root cause of the analytics error in Part B: the app had not yet declared "Protected customer data" use in the
Partner Dashboard (API access requests → Protected customer data access). Shopify blocks the `Order` object until
that is selected. Selected reasons: Store management, Analytics; no customer fields (name/email/phone/address) —
the app never reads them. For a custom app this needs no review; the deploy guide now lists it as a pre-deploy step.

Re-check on the embedded page (range 30 يوم), observed from the page:

| tile | expected (orders #1035, #1036, #1037) | observed | screenshot | result |
|---|---|---|---|---|
| أوردرات أخدت العرض | 3 (100% of 3 orders in range) | 3 — 100% من كل الأوردرات | admin-analytics-30d-after-pcd.jpg | ✅ |
| هدايا | 3 | 3 | same | ✅ |
| خصم 30% | 2 | 2 | same | ✅ |
| إجمالي الخصم | 3,830.00 ج.م | 3,830.00 ج.م | same | ✅ |
| السيناريو التاني كان هيدخل زيادة | 140.00 ج.م | 140.00 ج.م | same | ✅ |
| أكتر 10 منتجات خرجت هدايا | QA-A 1 / 1,200.00; QA-B 1 / 1,100.00; QA-C 1 / 900.00 | identical | admin-analytics-top-gifts-after-pcd.jpg | ✅ |

The stale "مفيش أوردرات" on 30 يوم in Part B was the 10-minute cache holding the earlier empty result; it cleared
after the TTL. Still not verified: the cache hit itself (timing only), `% من كل الأوردرات` on a store with non-offer orders in range.

## Part C — re-verification after the final review fixes (2026-10-30, controller)

| check | expected | observed | screenshot | result |
|---|---|---|---|---|
| One line QA-C × 27 (odd cart, Function rewritten to O(n)) | subtotal 12,330.00 = 13 paid + 1 at 30% (630) + 13 gifts; Function fuel well under 11M | E£12,330.00; rows: 13 × QA-C E£11,700.00, 1 × QA-C E£630.00 «خصم 30%», 13 × QA-C FREE «هدية»; TOTAL SAVINGS E£11,970.00; Function log fuel 758,194 instructions (before the fix: 8.05M) | qty27-checkout-after-fix.jpg | ✅ |
| Discount deactivated by hand in Shopify (`discountAutomaticDeactivate` → EXPIRED), page reloaded | status line «الخصم موجود في شوبيفاي لكنه مش شغال دلوقتي» | same text shown | admin-discount-deactivated-status.jpg | ✅ |
| Click «حفظ» with the discount EXPIRED | green banner; discount reactivated; status line back to «شغال» | banner «تم الحفظ وتم تحديث الخصم في شوبيفاي»; status «الخصم موجود وشغال في شوبيفاي»; Admin API read-back `status: ACTIVE` | admin-save-reactivates-discount.jpg | ✅ |

Not verified: a cart above 199 units (fixtures cover 27/51/99/199 units locally only); a 60-day range on a store with more than 250 orders (pagination + throttle retry are unit-tested with fakes only); reactivating a discount whose `endsAt` is in the past (ours has none).

## Final run (English strings) — see [FINAL.md](FINAL.md) and `screenshots/final/` (43 files). Earlier runs above used the Arabic labels.

## Team testing link (2026-10-30)
Development stores cannot drop the storefront visitor password (Shopify disables the toggle). For the team, a theme
preview share link was generated from a draft copy of the live theme ("Copy of test-data"); it opens without the
password: https://4ktaqylxkejwsk7b-86755410224.shopifypreview.com — catalogue: `/collections/all` on that host.
All catalogue products were reset to fixed base prices (no compare-at) except QA-M, which stays as the markdown test product.
