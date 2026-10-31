# دليل النشر على Cloudways — عرض BOGO لمتجر Le Voile

بعد إكمال هذا الدليل سيكون لديك تطبيق Laravel يعمل على `https://offer.arenahere.com`، ونسخة منشورة من تطبيق Shopify (الـ Function + الإعدادات)، وتطبيق مثبّت على المتجر الحقيقي في حالة «متوقف» بانتظار أن يشغّله المالك.

الأوامر بالإنجليزية داخل كتل `bash`، وكل أمر في كتلة مستقلة. كل أوامر Shopify CLI المذكورة هنا موجودة في الإصدار 4.9.4 (تُراجَع بـ `shopify app <command> --help`).

## 1. نظرة عامة وما تحتاجه قبل البدء

### مكوّنات المشروع

| المسار | الوظيفة |
|--------|---------|
| `shopify.app.toml` (في الجذر) | إعدادات التطبيق: الرابط، الصلاحيات، الـ webhooks |
| `extensions/bogo-discount/` | الـ Discount Function (API 2026-07) |
| `web/` | تطبيق Laravel 12 (الصفحة المدمجة). الـ webroot هو `web/public` |
| `shared/` | الثوابت المشتركة. يقرأ Laravel الملف `../shared/offer-constants.json` نسبةً إلى `web/` |

الملفان `web/shopify.web.toml` و`web/dev-server.cjs` مخصصان لـ `shopify app dev` على جهاز التطوير فقط ولا يُستخدمان على Cloudways.

### بيانات التطبيق

- المؤسسة في Dev Dashboard: **Arena Here Development** (المعرّف `129021558`).
- التطبيق: `offer-app`، المعرّف `levoile-bogo-offer`، الـ client_id: `46f2939b12c1c3a59f0da6f5b16d4416`.
- متجر التطوير: `levoilest.myshopify.com`.
- المتجر الحقيقي: `levoilestores.myshopify.com`.

### ما تحتاجه

- [ ] حساب Cloudways بصلاحية إنشاء تطبيق على السيرفر، ووصول SSH/SFTP.
- [ ] نطاق فرعي `offer.arenahere.com` يشير إلى سيرفر Cloudways، مع شهادة SSL مفعّلة (Let's Encrypt من لوحة Cloudways).
- [ ] وصول إلى مؤسسة Dev Dashboard **Arena Here Development** (للحصول على الـ secret ورابط التثبيت).
- [ ] على جهازك: Node.js وnpm وShopify CLI 4.9.4 وتسجيل دخول للـ CLI. أول أمر `shopify app` سيفتح المتصفح لتسجّل الدخول بنفسك؛ لا تشارك كلمة المرور مع أحد.
- [ ] وصول إلى لوحة Le Voile (لإيقاف توزيع الكوبونات) وإلى Shopify Admin للمتجر الحقيقي.
- [ ] **موافقة المالك الصريحة** قبل التثبيت على المتجر الحقيقي (القسم 6). كل ما قبل ذلك آمن لأنه لا يلمس المتجر الحقيقي.

## 2. إعداد تطبيق Cloudways

1. في Cloudways اختر السيرفر، ثم **Add Application**.
2. **Application**: `PHP` (ليس WordPress)، **Stack**: **Hybrid** (Apache مع Nginx). لا تستخدم Lightning/Nginx فقط لأنه يتجاهل `.htaccess`، وLaravel يحتاجه لتوجيه الطلبات.
3. اختر اسم التطبيق (مثلاً `levoile-offer`) وأنشئه.
4. من **Application Settings** اضبط **PHP Version** على `8.2` أو أحدث، وتأكد أن امتداد `pdo_mysql` مفعّل (مفعّل افتراضياً في Cloudways).
5. من **Domain Management** أضف `offer.arenahere.com` كنطاق أساسي، ثم من **SSL Certificate** ثبّت شهادة Let's Encrypt وفعّل **HTTP to HTTPS redirect**.
6. من **Access Details** سجّل بيانات قاعدة MySQL التي أنشأها Cloudways مع التطبيق (اسم القاعدة، المستخدم، كلمة المرور). إن أردت قاعدة مستقلة فأنشئها من **MySQL Management**.
7. اضبط الـ webroot على `web/public`:
   - من **Application Settings** ← **General** ← **Webroot** اكتب `public_html/web/public` (بعد رفع الكود في القسم 3 ليكون المجلد موجوداً)،
   - أو، إن لم يتوفر الخيار، اجعل `public_html` رابطاً رمزياً إلى `web/public` بعد الرفع (الأمر في القسم 3).

الجذر المقصود: ملفات `shopify.app.toml` و`extensions/` و`shared/` تبقى خارج الـ webroot، فلا تُخدَم للعموم.

## 3. رفع الكود وتهيئة Laravel

### 3.1 رفع الكود

الطريقة المفضلة: **Deployment via Git** من Cloudways (Application Management ← Deployment via Git)، مع فرع الإنتاج (`main`) ومسار النشر `public_html`. بديلاً عنها ارفع المستودع كاملاً بـ SFTP إلى `public_html`. المهم رفع المستودع كله (وليس مجلد `web/` وحده) لأن Laravel يقرأ `../shared/`.

اتصل بالسيرفر بـ SSH بمستخدم التطبيق (البيانات في **Access Details**)، ثم ادخل مجلد `web` داخل الكود المرفوع. مسار التطبيق على السيرفر يظهر في Access Details بالشكل `/home/master/applications/<مجلد-التطبيق>/public_html`:

```bash
cd /home/master/applications/<مجلد-التطبيق>/public_html/web
```

إن لم تجد خيار الـ webroot في اللوحة، اضبط الرابط الرمزي بعد الرفع (مرة واحدة)، من داخل `/home/master/applications/<مجلد-التطبيق>`:

```bash
mv public_html public_html_repo && ln -s public_html_repo/web/public public_html
```

في هذه الحالة ينشر Git إلى `public_html_repo` بدلاً من `public_html`، وتصبح أوامر القسم 3 داخل `public_html_repo/web`.

### 3.2 تثبيت الاعتماديات

```bash
composer install --no-dev --optimize-autoloader
```

### 3.3 ملف `.env`

```bash
cp .env.example .env
```

عدّل `web/.env` بالقيم التالية. لا تُرفع هذه القيم إلى git أبداً (الملف مُتجاهَل في `.gitignore`).

| المفتاح | القيمة في الإنتاج | الشرح |
|---------|-------------------|-------|
| `APP_NAME` | `"Le Voile BOGO"` | اسم التطبيق |
| `APP_ENV` | `production` | بيئة الإنتاج (النسخة المثال تحمل `local`، غيّرها) |
| `APP_KEY` | يُولَّد بالأمر أدناه | مفتاح التشفير، اتركه فارغاً الآن |
| `APP_DEBUG` | `false` | يجب أن يبقى `false`؛ `true` يكشف أسراراً في صفحات الأخطاء |
| `APP_URL` | `https://offer.arenahere.com` | العنوان العام للتطبيق، يجب أن يطابق `application_url` في `shopify.app.toml` |
| `LOG_LEVEL` | `warning` | مستوى السجل (النسخة المثال `debug`) |
| `DB_CONNECTION` | `mysql` | النسخة المثال تحمل `sqlite`، غيّرها |
| `DB_HOST` | `127.0.0.1` | سيرفر MySQL على نفس الجهاز |
| `DB_PORT` | `3306` | منفذ MySQL |
| `DB_DATABASE` | من Access Details | اسم القاعدة |
| `DB_USERNAME` | من Access Details | مستخدم القاعدة |
| `DB_PASSWORD` | من Access Details | كلمة مرور القاعدة |
| `SESSION_DRIVER` | `database` | الجلسات في MySQL |
| `CACHE_STORE` | `database` | الكاش في MySQL (يخزّن نتائج التحليلات 10 دقائق) |
| `SHOPIFY_API_KEY` | `46f2939b12c1c3a59f0da6f5b16d4416` | الـ client_id، قيمة عامة |
| `SHOPIFY_API_SECRET` | سرّي، من Shopify | انظر أدناه |
| `SHOPIFY_API_VERSION` | `2026-07` | إصدار Admin API، ويطابق إصدار الـ Function |
| `SHOPIFY_ALLOWED_SHOPS` | `levoilestores.myshopify.com,levoilest.myshopify.com` | المتاجر المسموح لها بفتح الصفحة؛ أي متجر غيرها يحصل على 401 |
| `SHOPIFY_SHARED_CONSTANTS_PATH` | `../shared/offer-constants.json` | مسار الثوابت المشتركة نسبةً إلى `web/` |
| `SHOPIFY_FUNCTION_TITLE` | `"BOGO Discount (Le Voile)"` | اسم الـ Function كما تُبلغ عنه Shopify؛ التطبيق يكتشف معرّفها وقت التشغيل ولا حاجة لإعداد آخر |

باقي المفاتيح في `.env.example` (البريد، Redis، AWS) تُترك كما هي لأن التطبيق لا يستخدمها.

**مصدر `SHOPIFY_API_SECRET`**: إما من جهازك (بعد تسجيل دخول CLI) من جذر المستودع:

```bash
shopify app env show
```

أو من Dev Dashboard ← التطبيق `offer-app` ← **Settings** ← **Secret**. الصقه في `.env` على السيرفر فقط. لا ترسله في محادثة ولا تضعه في ملف داخل المستودع. إن انكشف، أعد توليده من Dev Dashboard وحدّث `.env`.

### 3.4 المفتاح وقاعدة البيانات والكاش

```bash
php artisan key:generate
```

```bash
php artisan migrate --force
```

```bash
php artisan config:cache
```

```bash
php artisan route:cache
```

```bash
php artisan view:cache
```

بعد أي تعديل لاحق على `.env` أعد تشغيل `php artisan config:cache`، وإلا يبقى التطبيق على القيم القديمة.

### 3.5 صلاحيات الكتابة

يجب أن يستطيع مستخدم التطبيق الكتابة في `storage/` و`bootstrap/cache/`:

```bash
chmod -R ug+rwX storage bootstrap/cache
```

### 3.6 فحص سريع

```bash
curl -I https://offer.arenahere.com/
```

أي استجابة من Laravel (حتى 401 أو 404) تعني أن الـ webroot صحيح. إن ظهر `403`/صفحة Apache الافتراضية أو تحميل ملف PHP، فالـ webroot لا يشير إلى `web/public` (راجع القسم 9).

## 4. فحوصات `shopify.app.toml` قبل النشر

نفّذ هذه الفحوصات من جذر المستودع على جهازك قبل `shopify app deploy`، لأن النشر يرسل هذه الإعدادات إلى Shopify كما هي.

| الإعداد | القيمة المطلوبة |
|---------|-----------------|
| `application_url` | `https://offer.arenahere.com` |
| `[auth] redirect_urls` | `[ "https://offer.arenahere.com/auth/callback" ]` |
| `embedded` | `true` |
| `[access_scopes] scopes` | **بالضبط** `write_discounts,read_orders,read_products` |
| `[webhooks] api_version` | `2026-07` |
| اشتراكات `[[webhooks.subscriptions]]` | `app/uninstalled`، وموضوعات الامتثال `customers/data_request` و`customers/redact` و`shop/redact`، وكلها بـ `uri = "/webhooks"` |

### فحص الصلاحيات

```bash
grep scopes shopify.app.toml
```

يجب أن يطبع السطر التالي تماماً:

```
scopes = "write_discounts,read_orders,read_products"
```

أثناء اختبارات الجودة أُضيفت مؤقتاً الصلاحيتان `write_products` و`write_publications` لتهيئة بيانات متجر التطوير. **يجب أن تختفيا قبل النشر**: لو نُشرتا لطلبهما التطبيق من المالك عند التثبيت على المتجر الحقيقي وهما صلاحيتا كتابة لا يحتاجهما التطبيق. إن وجدتهما احذفهما من السطر ليبقى النص أعلاه فقط.

سبب كل صلاحية: `write_discounts` لإنشاء الخصم الآلي الوحيد الذي يستضيف الـ Function، و`read_orders` للتحليلات (آخر 60 يوماً فقط)، و`read_products` لقراءة `compareAtPrice` في حساب السيناريو الثاني.

### فحص إصدار الـ webhooks

```bash
grep api_version shopify.app.toml extensions/bogo-discount/shopify.extension.toml
```

يجب أن يظهر `2026-07` في الملفين. إن ظهر إصدار آخر في `[webhooks]` فصحّحه إلى `2026-07` قبل النشر.

### النشر

من جذر المستودع، جهّز اعتماديات الـ Function ثم تحقق من أنها تُبنى:

```bash
npm install
```

```bash
shopify app function build
```

ثم انشر:

```bash
shopify app deploy --version offer-2026-10-30 --message "BOGO launch build"
```

(الخياران `--version` و`--message` اختياريان. بدون `--version` يولّد Shopify اسماً تلقائياً. في بيئات CI التي لا تسمح بالتأكيد التفاعلي أضف `--force` أو `--allow-updates`؛ وللنشر دون إتاحة الإصدار للتجار أضف `--no-release`.)

**ما الذي يُنشَر**: إصدار جديد من التطبيق يضم (1) ملف الـ Function المبني `bogo-discount` و(2) إعدادات التطبيق من `shopify.app.toml` (الرابط والصلاحيات والـ webhooks)، لأن `include_config_on_deploy = true`. لا يُنشَر كود Laravel؛ ذلك يتم على Cloudways (القسم 3).

**قراءة النتيجة**: يطبع الأمر في آخره اسم الإصدار المنشور (مثل `offer-2026-10-30`) ورابطاً إلى صفحته في Dev Dashboard. سجّل هذا الاسم، فهو ما ستحتاجه للرجوع. لعرض كل الإصدارات وأيها المفعّل حالياً:

```bash
shopify app versions list
```

### طلب Protected customer data (قبل التثبيت)

بدون هذا الطلب ترفض Shopify قراءة كائن الطلبات (Orders)، وتظهر في كتلة التحليلات رسالة الخطأ «This app is not approved to access the Order object». نُفّذ مرة واحدة لهذا التطبيق بتاريخ 2026-10-30 (الحالة Draft)؛ كرّره فقط إذا أُعيد إنشاء التطبيق.

1. Partner Dashboard ← التطبيق `offer-app` ← **API access requests** ← **Protected customer data access** ← **Request access**. الرابط المباشر لهذا التطبيق: `https://partners.shopify.com/3456935/apps/441694388225/customer_data` (النمط: `https://partners.shopify.com/<partner-id>/apps/<app-id>/customer_data`).
2. في الخطوة 1 اضغط **Select** بجانب **Protected customer data**.
3. فعّل السببين **Store management** و**Analytics** ثم **Save**.
4. **لا تختر أي حقول عملاء محمية** (الاسم/البريد/الهاتف/العنوان)؛ التطبيق لا يقرؤها أبداً.

التطبيق مخصص ومثبّت على متجر المالك نفسه فلا مراجعة مطلوبة: متاجر التطوير تحصل على الوصول فور الخطوة 1، وبند «Data protection details» في الخطوة 2 مطلوب فقط لمراجعة App Store.

## 5. خطوات ما قبل الإطلاق (قرار المالك بتاريخ 2026-10-30)

يجب ألا يزاحم كود خصم العرض في صفحة الدفع، لذلك:

1. **أوقف توزيع الكوبونات** في لوحة Le Voile (كوبونات الترحيب/الأونلاين التي تُنشئ أكواد خصم حقيقية في Shopify).
2. من Shopify Admin للمتجر الحقيقي ← **Discounts**: عطّل (Deactivate) كل أكواد الخصم النشطة. لا تحذفها، التعطيل كافٍ ويمكن عكسه.
3. حدّد المنتجات التي يهمّ فيها «السعر الأصلي» (السيناريو الثاني يعتمد على `compareAtPrice`) وتأكد من ضبط **Compare-at price** لها في Shopify Admin.
4. **اختبر أولاً على متجر التطوير** `levoilest.myshopify.com`: ثبّت التطبيق، احفظ إعدادات العرض، واختبر سلة حقيقية حتى صفحة الدفع، بما في ذلك كتابة كود خصم لرؤية ما يراه المتسوق.
5. تأكد أن اختبار متجر التطوير نجح وأن القسم 4 مكتمل (الصلاحيات الثلاث فقط، والنشر تم).
6. من Shopify Admin ← **Discounts**: عطّل (Deactivate) أو حدّد تاريخ انتهاء لكل **خصم آلي نشط آخر** (basic وBXGY وfree shipping). Shopify يطبّق خصماً آلياً واحداً فقط على المنتجات، وأي خصم آلي آخر قد يزيح العرض.
7. خصم التطبيق مضبوط بـ `combinesWith` كلها `false`، لذلك تُرفض أكواد الخصم في صفحة الدفع (اختبار QA10).
8. **Set the tag name on the app page** (field **Eligible product tag**, default `FallOffer`) and **Save**; then **tag every product in the offer with exactly that tag** (Products → bulk edit → add tag; case-sensitive, no commas). Untagged products sell at normal price and never count as a gift or a pair. Changing the tag later needs no redeploy: Save writes it to the discount's `$app:bogo/config` metafield, which the Function reads as its `$eligibleTags` input variable. (اكتب اسم الوسم في صفحة التطبيق — الافتراضي `FallOffer` — ثم احفظ، وضع نفس الوسم بالضبط على كل منتجات العرض، على متجر التطوير والمتجر الحقيقي.)
   - After deploying this version (the extension toml gained `input_variables`), open the app page and click **Save** once on each store: the discount must carry the metafield, otherwise the Function's required `$eligibleTags` variable is null and the run fails (no discount at checkout).

## 6. التركيب على المتجر الحقيقي

> لا تنفّذ هذا القسم إلا بعد **موافقة المالك الصريحة**. بدونها توقف عند نهاية القسم 5.

1. **اختر طريقة التوزيع (خطوة لا رجعة فيها)**: افتح Partner Dashboard ← التطبيق `offer-app` ← **Distribution**
   (https://partners.shopify.com/3456935/apps/441694388225/distribution) ← اختر **Custom distribution** ← أدخل نطاق المتجر `levoilestores.myshopify.com` ← **Generate link** لتوليد رابط التثبيت.
   - **هذا الاختيار نهائي ولا يمكن التراجع عنه.** لا تختر **Public distribution** أبداً: اختيارها بالخطأ يعني إعادة إنشاء التطبيق من الصفر بـ `client_id` جديد (ومعه تغيير `.env` و`shopify.app.toml` ومالك الـ metafield).
   - التوزيع المخصّص (Custom distribution) يمنح صلاحية **Protected customer data** تلقائياً، فلا حاجة لطلب منفصل لهذا المتجر.
   - متجر التطوير `levoilest.myshopify.com` يظل يعمل كما هو عبر `shopify app dev`.
2. افتح رابط التثبيت الذي ولّدته في الخطوة 1. يفتح الرابط شاشة موافقة في Shopify Admin للمتجر `levoilestores.myshopify.com`؛ يجب أن تعرض الصلاحيات الثلاث فقط (`write_discounts` و`read_orders` و`read_products`).
3. وافق على التثبيت من حساب مدير المتجر.
4. افتح التطبيق من Shopify Admin ← **Apps** ← `offer-app`. تظهر الصفحة المدمجة.
5. **تأكد أن الصفحة تعرض الحالة "Offer is OFF"**. هذه هي الحالة الافتراضية بعد التثبيت، والعرض غير فعّال للمتسوقين.
6. أخبر المالك أن التطبيق جاهز. **المالك هو من يشغّل العرض** من مفتاح التشغيل في الصفحة. لا تشغّله أنت.

**ماذا يفعل زر "Save"**: يكتب الإعدادات في metafield للمتجر (ومنها `enabled`)، ويضمن وجود **خصم آلي واحد** (Automatic discount) مرتبطاً بالـ Function. الـ Function تقرأ الـ metafield في كل سلة؛ لذلك يتغير سلوك السلة خلال ثوانٍ من الحفظ دون إعادة نشر.

## 7. التشغيل والإيقاف والرجوع

### الإيقاف الفوري (Kill switch)

أطفئ مفتاح التشغيل من صفحة التطبيق واحفظ. يكتب ذلك `enabled:false` في الـ metafield فتُرجع الـ Function «لا خصومات» خلال ثوانٍ. هذا أسرع وأأمن أسلوب للإيقاف، ولا يمس الكود.

### الرجوع إلى إصدار سابق من الـ Function

اعرض الإصدارات:

```bash
shopify app versions list
```

ثم أطلق الإصدار السابق المعروف بأنه سليم (الاسم كما ظهر في القائمة):

```bash
shopify app release --version <previous>
```

مثال: `shopify app release --version offer-2026-10-30`. في بيئات غير تفاعلية أضف `--allow-updates` (أو `--allow-deletes`). الرجوع يغيّر الـ Function والإعدادات معاً إلى ما كان في ذلك الإصدار، فتأكد أن إعدادات الإصدار المختار (خصوصاً الصلاحيات) هي المقصودة.

### إيقاف الخصم من Shopify مباشرة

إن تعذّر الوصول إلى الصفحة: Shopify Admin ← **Discounts** ← افتح الخصم الآلي الخاص بالتطبيق (اسمه **Buy One Get One**، من `discountTitle` في `shared/offer-constants.json`؛ وتظهر للمتسوق على السطور الرسائل `BOGO Gift` و`BOGO {pct}% off` (مثل `BOGO 30% off`)) ← **Deactivate**. ملاحظة: أول «حفظ» لاحق من صفحة التطبيق يعيد تفعيل هذا الخصم تلقائياً (ويعرض خطأ أحمر إن رفض Shopify التفعيل).

### التطوير بعد الإطلاق

بعد الإطلاق ثُبّتت `automatically_update_urls_on_dev = false` في `shopify.app.toml` حتى لا يستبدل `shopify app dev` رابط التطبيق الحقيقي. لتشغيل `shopify app dev` مجدداً:

1. أنشئ إعداد تطوير منفصلاً: `shopify app config link` (سمّه مثلاً `dev`) وأشِر به إلى تطبيق **جديد** في Dev Dashboard.
2. شغّل `shopify app dev --config dev`.
3. **لا تشغّل `app dev` أبداً على إعداد التطبيق الحقيقي.**

## 8. استكشاف الأخطاء

| العرض | السبب | الحل |
|-------|-------|------|
| `401` عند فتح الصفحة | توقيع HMAC في الاستعلام غير صالح (`SHOPIFY_API_SECRET` خاطئ أو قديم)، أو المتجر ليس ضمن `SHOPIFY_ALLOWED_SHOPS` | قارن السر في `.env` بالسر في Dev Dashboard، وتأكد أن النطاق مكتوب بالضبط `xxx.myshopify.com` بحروف صغيرة، ثم `php artisan config:cache` |
| إطار (iframe) فارغ داخل Shopify Admin | سياسة CSP `frame-ancestors` أو ترويسة `X-Frame-Options` تمنع التضمين | تأكد أن الطلب يمر على middleware `EmbeddedCsp` (يضع `frame-ancestors` للمتجر وللنطاق `admin.shopify.com` ويزيل `X-Frame-Options`)، ولا تضف `X-Frame-Options` من إعدادات Cloudways/Apache. جرّب فتح الصفحة مباشرة من خلال Shopify Admin وليس من المتصفح |
| صفحة Apache الافتراضية أو `403` أو تحميل ملف PHP | الـ webroot لا يشير إلى `web/public` أو الـ stack ليس Hybrid | راجع القسم 2 |
| `500` فوراً بعد النشر | `APP_KEY` فارغ، أو `storage/` غير قابل للكتابة، أو لم تُنفَّذ الهجرات | `php artisan key:generate`، ثم `chmod -R ug+rwX storage bootstrap/cache`، ثم `php artisan migrate --force`، ثم أعد `config:cache`. التفاصيل في `storage/logs/laravel.log` |
| التحليلات فارغة أو أقدم من 60 يوماً | صلاحية `read_orders` تعطي آخر 60 يوماً فقط | هذا حد من Shopify، والنطاقات مقيّدة بـ 60 يوماً عمداً |
| التحليلات ترجع `429` | الحد 10 طلبات في الدقيقة لكل متجر | انتظر دقيقة. النتائج مخزنة 10 دقائق فالتحديث المتكرر غير مفيد |
| حُفظ الـ metafield لكن لا يوجد خصم في Shopify | الصلاحية `write_discounts` غير ممنوحة، أو الـ Function غير منشورة | أعد قراءة الصلاحيات الممنوحة للتطبيق (Dev Dashboard)، ونفّذ `shopify app deploy`، وتأكد أن الـ Function تظهر في `shopifyFunctions` (يكتشفها التطبيق بالعنوان `SHOPIFY_FUNCTION_TITLE`)، ثم أعد الحفظ |
| تغييرات `.env` لا تظهر | الإعدادات مخزنة في الكاش | `php artisan config:cache` |
| طلب إعادة تفويض أو صلاحيات إضافية عند التثبيت | الصلاحيات المنشورة تختلف عن الثلاث المطلوبة | راجع القسم 4 ثم أعد النشر |

## 9. ملاحظات تشغيلية

- التحليلات تغطي **آخر 60 يوماً فقط** (حد صلاحية `read_orders`).
- النتائج مخزنة في الكاش **10 دقائق**.
- يُحتسب بحد أقصى **2000 طلب** لكل استعلام تحليلات.
- نقطة التحليلات محدودة بـ **10 طلبات في الدقيقة لكل متجر**.
- الـ webhook المطلوب على المسار `/webhooks` (إلغاء التثبيت وموضوعات الامتثال) يُسجَّل تلقائياً من `shopify.app.toml` عند `shopify app deploy`.
- قَبِل المالك نتائج `npm audit` بتاريخ 2026-10-30: هي اعتماديات توليد أكواد وقت البناء ضمن `@shopify/shopify_function` وليست داخل الـ Wasm المنشور (0 تطابقات لـ lodash/graphql-tools في `dist/function.js`).
- المبالغ تُرسل بخانتين عشريتين؛ المتاجر التي تبيع بعملات ثلاثية الخانات (KWD وBHD وOMR) سيُقرَّب خصمها للأسفل بما يصل إلى فلس واحد. Le Voile تبيع بالجنيه EGP.
- أي تغيير في اسم النطاق يتطلب تحديث `application_url` و`redirect_urls` في `shopify.app.toml` و`APP_URL` في `.env` ثم إعادة النشر.
