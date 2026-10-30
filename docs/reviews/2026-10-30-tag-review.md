# FallOffer tag eligibility review (182d2c9..c44b62b)

Verdict: Ship. No Critical or Important issues.

1. Input query: OK. `hasAnyTag(tags: [String!]!)` on Product matches schema.graphql (Boolean!). input-query.test.js reads shared/offer-constants.json and the .graphql file and asserts the literal.
2. Adapter: OK. isEligible = ProductVariant && isGiftCard === false && hasAnyTag === true; filtering occurs before candidate building, so untagged lines never enter pairing or expansion. Fixtures verified by hand: A alone 30% of 1200 = 360.00; A,B tagged + C untagged -> B gift 1100.00 only; all_untagged -> empty.
3. Analytics: OK. in_array strict on exact tag (case-sensitive; "falloffer" excluded). Scenario 2 gross, charged and the 'min' alt all use eligibleLines; ordersTotal = count(orders) untouched; gift/percent tiles from allocations; legacy Arabic fixtures all carry tags. Hand check: charged 1830, min alt pays 1970, extra 140; totalDiscount 1370.
4. Admin note (+test), deploy.md step 8, spec change log present. No secrets, no stray files.

Minor (non-blocking):
- Orders placed before tagging whose products are later untagged/deleted drop out of the recompute baseline (scenario2Extra) but their allocations still count in totals; acceptable.
- Commit gap: docs/reviews/_tag.diff is untracked; do not commit it.
