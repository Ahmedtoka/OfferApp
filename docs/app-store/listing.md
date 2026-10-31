# Pairly: BOGO Offers, App Store listing draft

Status: draft, 2026-10-31. Nothing here is entered in the Partner Dashboard. English is the primary listing. Arabic is placed alongside as a draft: Shopify auto-translates an English listing into only the languages its checklist names (Brazilian Portuguese, Danish, Dutch, French, German, Simplified Chinese, Spanish, Swedish), so whether the form accepts a custom Arabic translation is **unverified**. The Arabic text is not reviewed by a native editor.

Rules applied (from Shopify's app requirements checklist): lead with own brand, no outcome guarantees, no pricing in screenshots, no competitor names, no Shopify logos in feature images, support info and links in their own fields.

## Name
- English: Pairly: BOGO Offers
- Arabic: Pairly: عروض اشترِ وخذ آخر
- Availability on the App Store: **unverified** until the name is entered in the Partner Dashboard. Fallbacks (spec §16): TwoFer: BOGO Deals, GiftPair: BOGO Discounts. The name must equal `name` in `shopify.app.bogo.toml` and `APP_NAME`.

## App introduction
- English: Buy-one-get-one offers for any quantity, with a choice of who the maths favours.
- Arabic: عروض اشترِ واحداً واحصل على آخر لأي كمية، وأنت تختار لمصلحة مَن يُحسب الخصم.

## App details
- English: Pairly runs one buy-one-get-one offer in your store. Every two eligible units give one as a gift, and a leftover unit gets the percent you set. Pick products by tag or collection, set start and end dates, and decide whether coupons combine. Choose whether the calculation favours the customer or your company, try it on a sample cart, then switch it on. Analytics compare offer orders with other orders. Theme blocks show it on product and cart pages with no code edits. English and Arabic.
- Arabic: يشغّل Pairly عرضاً واحداً من نوع اشترِ واحداً واحصل على آخر. كل وحدتين مؤهلتين تمنحان واحدة هدية، والوحدة الزائدة تأخذ النسبة التي تحددها. اختر المنتجات بوسم أو مجموعة، وحدد تاريخي البداية والنهاية، وقرر هل تجتمع الكوبونات مع العرض. اختر هل يُحسب الخصم لمصلحة العميل أو شركتك، وجرّبه على سلة تجريبية ثم شغّله. التحليلات تقارن طلبات العرض بغيرها. كتل القالب تعرضه في صفحتي المنتج والسلة دون تعديل الكود. بالإنجليزية والعربية.

## Feature list
1. One rule for any quantity, with the best split found automatically
   - قاعدة واحدة لأي كمية، وأفضل توزيع يُحسب تلقائياً
2. Choose the calculation: favour the customer or your company
   - اختر طريقة الحساب: لمصلحة العميل أو لمصلحة شركتك
3. Eligible products by tag or collection, with start and end dates
   - منتجات مؤهلة بوسم أو مجموعة، مع تاريخي بداية ونهاية
4. Coupons stay separate unless you let them combine
   - الكوبونات منفصلة ما لم تسمح باجتماعها مع العرض
5. Test page shows what each unit pays before the offer goes live
   - صفحة اختبار تعرض سعر كل وحدة قبل تشغيل العرض
6. Analytics: offer sales, total discount and average order value
   - تحليلات: مبيعات العرض وإجمالي الخصم ومتوسط قيمة الطلب
7. Product badge and cart message through theme blocks, no code edits
   - شارة المنتج ورسالة السلة عبر كتل القالب دون تعديل الكود
8. English and Arabic admin, with right-to-left layout
   - لوحة بالإنجليزية والعربية مع اتجاه من اليمين إلى اليسار

## Character counts
Counted by script (Unicode code points). Limits are the checklist's: name 30, introduction 100, details 500, each feature 80. A row passes only if both languages fit.

| Field | Limit | English | Arabic | Result |
|---|---|---|---|---|
| App name | 30 | 19 | 26 | OK |
| Introduction | 100 | 80 | 76 | OK |
| Details | 500 | 490 | 424 | OK |
| Feature 1 | 80 | 66 | 48 | OK |
| Feature 2 | 80 | 59 | 48 | OK |
| Feature 3 | 80 | 64 | 51 | OK |
| Feature 4 | 80 | 49 | 46 | OK |
| Feature 5 | 80 | 62 | 44 | OK |
| Feature 6 | 80 | 62 | 53 | OK |
| Feature 7 | 80 | 66 | 55 | OK |
| Feature 8 | 80 | 51 | 55 | OK |

## Other fields (not yet filled)
| Field | Draft |
|---|---|
| Pricing | One recurring plan, $19.99 per month, 14-day free trial, entered only in the pricing section. Waits on Public distribution. See `price-check.md`. |
| Search terms (up to 5) | buy one get one; BOGO; free gift; automatic discount; offer |
| Integrations (up to 6) | None. Do not list Shopify. |
| Privacy policy URL | `SITE_URL`/privacy after the domain is mapped and the owner has accepted the text |
| Support email, support/FAQ/docs links | Owner's address (`SHOPIFY_SUPPORT_EMAIL`); docs at `SITE_URL`/docs |
| Demo store URL | Waiting on the owner (dev store has a visitor password) |
| Merchant install eligibility | Online Store sales channel; any country and currency. Confirm in the form, **unverified** |
| Categories and structured features | To choose in the form, discounts and promotions. Owner decision at submission |
| Languages the UI supports | English, Arabic |

## Assets
| Asset | Spec | State |
|---|---|---|
| App icon | 1200 x 1200, PNG, no text | `docs/app-store/assets/icon-1200.png`, 1200 x 1200 checked by script, no text, blue field with a white disc and a yellow ring |
| Feature media | 2-3 minute video (screencast at most 25 percent), or one 1600 x 900 image, solid background, alt text, no pricing | **Not made.** To produce |
| Screenshots | 3 to 6, 1600 x 900, browser chrome cropped, no pricing, no PII, alt text | **None made.** Shot list below |

### Screenshots still to capture
The four images in `docs/qa/pairly/screenshots/` are QA evidence, not store assets: they are not at 1600 x 900, show the browser frame and Shopify's dev console, and one shows a personal email address in the sidebar. Capture fresh ones on the prepared dev store, with the owner's password entered where the storefront is needed.

1. Settings page with the offer on, tag chosen, state Running. Alt: "Pairly settings with the offer running". Needs: dev store only.
2. Test page with a three-item sample cart, both methods side by side. Alt: "Test page comparing the two calculation methods". Needs: dev store only.
3. Analytics with the four numbers and the one-sentence result. Alt: "Pairly analytics for the last 30 days". Needs: dev store with orders and `read_orders` granted.
4. Onboarding steps on first open. Alt: "Five setup steps". Needs: fresh install.
5. Storefront product badge and cart message. Alt: "Product badge and cart message". **Blocked**: storefront password and old "Buy One Get One" discount on levoilest.
6. Arabic admin with right-to-left layout. Alt: "Pairly in Arabic". Needs: Arabic admin session, not yet tested live.

Do not include the pricing step, the billing approval page, or any review or rating text in any image.
