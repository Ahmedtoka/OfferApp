# Requirements evidence: Shopify app requirements checklist

Source walked on 2026-10-31: https://shopify.dev/docs/apps/launch/app-requirements-checklist (fetched, sections 1 to 19). The checklist points to the numbered App Store requirements (1.1, 1.2, 2.1, 2.2, section 3, 4.2, 4.3, 4.5, 5.x) for detail; those pages were **not** read for this pass, so each row is judged against the checklist's own wording only.

States: **Met** = there is evidence in the repo or in the QA record. **Not verified** = not tested, or only Shopify can decide. **Waiting on owner** = needs the owner's account, decision or text. **Not applicable** = our reading that the category does not describe this app; Shopify may disagree. Nothing is marked Met on the strength of intent alone.

Counts (53 rows): Met 20, Not verified 22, Waiting on owner 8, Not applicable 3.

QA record: `docs/qa/pairly/README.md` (dev store levoilest, app 1.0.0-qa). Tests: `web/tests/Feature/*`.

| # | Requirement | State | Evidence or what is missing |
|---|---|---|---|
| 1 | Not a prohibited or restricted configuration | Not verified | A discount Function app is not on the checklist's list as we read it; Shopify decides at review |
| 2A.1 | OAuth immediately on install | Met | Install on the dev store showed Shopify's consent then the embedded page (QA row 2); `AuthMiddlewareInstallRaceTest`; managed install (`use_legacy_install_flow = false`) |
| 2A.2 | Same for a merchant who reinstalls | Not verified | Uninstall and reinstall not run live (QA, Not verified) |
| 2B | Only needed scopes; extras optional | Met | `shopify.app.bogo.toml`: required `write_discounts`, `read_products`; optional `read_orders`, `read_inventory`; QA row 6 shows `read_orders` asked separately. The `read_inventory` flow was not run live |
| 2C.1 | No pop-ups for OAuth or charge approval | Met | Approval is a top-level navigation, `open(url, '_top')` in `web/public/app.js:445`. Not run live against a real charge |
| 2C.2 | Secondary payments note | Not applicable | The app adds no payments to orders |
| 3A.1 | Follows App Design Guidelines | Not verified | Admin uses Shopify's web components; no guideline audit was done |
| 3A.2 | Meets functionality conditions in the requirements | Not verified | Requirement pages not read; no review run |
| 3B.1 | Charges through Shopify billing | Not verified | Implemented (`web/app/Services/Billing.php`, `BillingTest`); no live call succeeds yet: Shopify answered "Apps without a public distribution cannot use the Billing API" (QA row 8) |
| 3B.2 | Upgrade and downgrade without support or reinstall | Not verified | Single plan, so there is nothing to switch to; cancel path untested live |
| 3B.3 | Charges appear in the app charge history | Waiting on owner | Needs Public distribution, then a test charge on the dev store |
| 3B.4 | Enterprise pricing in the additional-charges field | Waiting on owner | Entered in the Partner Dashboard form; only one plan exists |
| 3C.1 | Chrome cookie behaviour, SameSite | Met | No session or cookies on public and embedded routes (commit d58b8e0, `PublicAppSecurityTest::test_public_and_embedded_responses_are_stateless`) |
| 3C.2 | Core functionality unchanged after approval | Not verified | Applies after approval |
| 4A | Lighthouse drop at most 10 points | Not verified | Needs a storefront with the embed on, measured before and after, averaged runs; storefront blocked (QA, Not verified) |
| 5A.1 | App name 30 characters or fewer | Met | "Pairly: BOGO Offers" is 19 characters (`listing.md` table) |
| 5A.2 | Distinctive, not confusingly similar to another app | Not verified | Name availability and similarity unchecked until entered in the Partner Dashboard; "BOGO" is generic, the brand leads |
| 5A.3 | Same name in TOML and listing | Met | `name` in `shopify.app.bogo.toml` and `APP_NAME` in `web/.env.example` are both "Pairly: BOGO Offers" |
| 5A.4 | Icon 1200 x 1200, JPEG or PNG, no text | Met | `docs/app-store/assets/icon-1200.png`, 1200 x 1200 read from the file header, viewed: no text |
| 5B.1 | Feature media (video or 1600 x 900 image) | Not verified | Not made |
| 5B.2 | Demo store URL with instructions | Waiting on owner | Dev store has a visitor password and an old discount still active on it |
| 5B.3 | 3 to 6 screenshots at 1600 x 900, no pricing | Not verified | None made; shot list in `listing.md` |
| 5B.4 | Introduction 100 characters or fewer | Met | 80 characters, counted by script; no guarantees |
| 5B.5 | Details 500 characters or fewer | Met | 490 characters, counted by script |
| 5B.6 | Features 80 characters or fewer each | Met | 8 features, longest 66, counted by script |
| 5B.7 | Integrations up to 6, no Shopify | Met | None listed |
| 5C | Pricing set in its section, 14-day trial | Waiting on owner | Entered in the Partner Dashboard after Public distribution; code already uses a 14-day trial |
| 5D | Languages the UI supports listed | Not verified | English and Arabic exist; Arabic text is a draft never reviewed live; whether the form accepts Arabic is unknown |
| 5E.1 | Privacy policy in the listing | Waiting on owner | Page exists at `/privacy` (`SiteTest`) as a draft; needs the owner's acceptance, legal name and support email |
| 5E.2 | Categories, tags, search terms, subtitle | Waiting on owner | Drafts in `listing.md`; chosen in the form |
| 5E.3 | FAQ, docs, support links on dedicated pages | Met | `/docs` and an FAQ exist on the site (`SiteTest`); on a temporary URL until the domain is mapped |
| 5F | Install eligibility (channels, geography, currency) | Waiting on owner | Set in the submission form; the app uses the cart's presentment currency |
| 5G.1 | Reviewer instructions with expected outcome per case | Met | `docs/app-store/reviewer-instructions.md`; several cases not yet run live |
| 5G.2 | Screencast in English | Not verified | Not recorded |
| 5G.3 | Test credentials for third-party integrations | Not applicable | No third-party integration |
| 6 | App secure before submission | Not verified | `docs/security/2026-10-30-review.md` covers the first app; the 2026-10-31 review of the public app was not present when this was written. Fix commits exist (a0daf7a, 8db3d81, 70e4615, 5fc3476, a1f0771, e525e3c) |
| 7.1 | Customer data handled properly | Met | The app reads no customer fields; Protected customer data reasons saved: Store management, Analytics; analytics numbers appeared after the declaration (QA row 6) |
| 7.2 | Mandatory privacy webhooks | Not verified | Subscribed in the TOML and handled (`WebhookTest`, `shop/redact` deletes the row); live delivery not seen |
| 7.3 | `app/uninstalled` removes the token | Met | `PublicAppSecurityTest`, `WebhookTest` (tests only; live uninstall not run) |
| 7.4 | No API deprecated within 90 days | Not verified | Uses Admin API 2026-07; its deprecation date was not checked |
| 8.1 | Shopify-specific help documentation | Met | `/docs` page (`SiteTest`) |
| 8.2 | In-app context, Polaris help guidelines | Not verified | Onboarding steps exist; guideline not audited |
| 8.3 | Emergency developer contact kept current | Waiting on owner | Entered in the Partner Dashboard |
| 9.1 | Theme app extension, no manual code changes | Met | `extensions/pairly-theme` (app embed, product badge), deployed after fix 89a8878; no theme code edited |
| 9.2 | Works in the theme editor and online editor | Not verified | Needs the storefront |
| 9.3 | Setup instructions and deep links | Not verified | Instructions in docs; deep link into the theme editor not tested live |
| 9.4 | Merchant can preview changes | Not verified | Not tested |
| 9.5 | Minimal branding, no review requests or app promotion | Met | Searched `extensions/pairly-theme` for review or promotion text: none. Rendering unseen |
| 10.1 | App Bridge and session tokens | Met | App Bridge script in `home.blade.php`; `VerifySessionToken`, `AuthMiddlewareTest`; embedded page loaded on the dev store |
| 10.2 | No third-party cookies or local storage | Met | Stateless responses test; searched `web/resources/views` and `web/public/app.js` for localStorage and sessionStorage: none |
| 10.3 | Works in Chrome incognito | Not verified | Not run |
| 10.4 | Max modal only from merchant actions | Not verified | Not used as far as the admin code shows; not tested |
| 11-19 | Product sourcing, mobile builders, sales channels, purchase options, donations, payments, post-purchase, checkout, blockchain | Not applicable | The app is an online-store discount; none of these categories fits our reading. "Checkout apps" concerns checkout UI extensions, which Pairly has none of |
