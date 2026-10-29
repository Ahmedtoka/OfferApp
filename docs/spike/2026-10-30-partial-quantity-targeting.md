# Spike — can a Discount Function target part of a cart line's quantity? (risk §10.1)

**Verdict: GO.**

| Item | Value |
|---|---|
| Shopify CLI | 4.9.4 |
| Function API version (extension toml) | `2026-07` |
| App | `offer-app`, client_id `46f2939b12c1c3a59f0da6f5b16d4416`, Dev Dashboard org 129021558 "Arena Here Development" |
| Dev store | `levoilest.myshopify.com` (LevoileSt, EGP, partner development store) |
| Function id | `01a189fa-391a-7bbd-bfd2-c21d3e57a430` ("BOGO Discount (Le Voile)") |
| Automatic discount used | `gid://shopify/DiscountAutomaticNode/1633016152368` (created as "spike", then renamed to the real title «اشتري واحدة وخد واحدة»; combinesWith all false; class PRODUCT) |

## Schema evidence (`extensions/bogo-discount/schema.graphql`)

```graphql
input CartLineTarget {
  id: ID!
  """The number of line items that are being discounted. The default value is `null`, which represents
  the quantity of the matching line items. The value is validated against: > 0."""
  quantity: Int
}
input ProductDiscountCandidateFixedAmount { amount: Decimal!  appliesToEachItem: Boolean = false }
enum ProductDiscountSelectionStrategy { ALL FIRST MAXIMUM }
```

## Local run (fixture: one line, quantity 3, 900.00 each)

`shopify app function run … --export cart-lines-discounts-generate-run` returned two candidates in one
`productDiscountsAdd` with `selectionStrategy: ALL`, each targeting `cartLine { id, quantity: 1 }`:
`هدية` −900.00 and `خصم 30%` −270.00. `success: true`.

## Dev-store run (real cart, real checkout)

Product "Arabian Nights Kaftan" (900.00, no compare-at), quantity 3, dev preview via `shopify app dev`.

- Cart page splits the line into three rows: 900.00 (paid), 630.00 tagged «خصم 30%», 0.00 tagged «هدية»;
  subtotal **1,530.00** → `docs/spike/cart-qty3.jpg`.
- Checkout shows the same three rows, subtotal **1,530.00**, "TOTAL SAVINGS E£1,170.00" → `docs/spike/checkout-qty3.jpg`.
- Function log (`.shopify/logs/…`): input `cart.lines[0].quantity = 3`, `shop.metafield` present after `metafieldsSet`,
  fuel ≈ 0.42M instructions, status success.

So two candidates may target the same cart line with different `quantity` values and both apply at cart and
checkout. The per-candidate `message` is rendered as the line's discount label.

## Other facts learned

- Writing the app-owned shop metafield `$app:bogo.config` (type `json`) via `metafieldsSet` needs **no extra scope**;
  Shopify stores it under the resolved namespace `app--441694388225--bogo` and the Function reads it with
  `shop.metafield(namespace: "$app:bogo", key: "config")`.
- With the metafield absent the real Function returns `{ operations: [] }` (seen in checkout before the metafield was set).
- Scopes auto-granted on the dev store: `read_orders, read_products, write_discounts` — the app cannot create products
  (`productSet` → ACCESS_DENIED), so QA products are created from the admin UI, not the API.
- Dev storefront is password-protected (Online Store → Preferences); QA enters it in the browser.
