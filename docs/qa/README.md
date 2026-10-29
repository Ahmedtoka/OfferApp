# QA storefront - BLOCKED (2026-10-30)

Store: https://levoilest.myshopify.com. Function: dev preview via shopify app dev.

## Result
No scenario could be run. `/cart/add?id=68212280688944&quantity=1` (QA-A) returns the storefront page
"Cart Error: Cannot find variant" (Request ID 58b45b8d-4583-437e-914a-f2336388c715-1793317217).

Evidence from the storefront (same browser session):
- `/products.json?limit=250` returns 58 products, none titled "QA*", and variant id 68212280688944 is not among them.
- `/search/suggest.json?q=QA&resources[type]=product` returns no products.

Likely cause: the QA-A..QA-M products are not published to the Online Store sales channel (or are draft). Fixing this needs an admin change, which this task forbids.

| scenario | cart | expected subtotal | observed subtotal | labels observed | screenshot | result |
|---|---|---|---|---|---|---|
| all 21 | - | - | not run | - | - | NOT TESTED |

## Not verified
All 21 scenarios, including the QA10 coupon scenario.
