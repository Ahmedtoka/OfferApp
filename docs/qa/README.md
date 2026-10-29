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
