{{--
    Le Voile BOGO — the single embedded admin page (Arabic, RTL).

    Polaris web components, verified 2026-10-30 against
      https://shopify.dev/docs/api/app-home/polaris-web-components
      https://shopify.dev/docs/api/app-home/latest/web-components/versioning   (loader: polaris-2.js after app-bridge.js)
    Tags used: s-page (heading), s-section (heading), s-switch (label, checked),
      s-number-field (label, value, min, max, step, suffix, inputMode, error),
      s-button (variant, loading, disabled), s-banner (tone, dismissible), s-select / s-option (label, value),
      s-link (href, target), s-spinner, s-box (padding, background, borderRadius), s-text (color, tone, type),
      s-table / s-table-header-row / s-table-header (listSlot, format) / s-table-body / s-table-row / s-table-cell.

    Only public values are rendered here: the App Bridge api key and the shared offer constants.
    The API secret and every access token stay on the server; app.js authenticates each /api call
    with a short-lived App Bridge session token.
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <title>{{ $pageTitle }}</title>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-2.js"></script>
    <link rel="stylesheet" href="/app.css?v={{ $assetVersion }}">
    <script type="module" src="/app.js?v={{ $assetVersion }}"></script>
</head>
<body>
<s-page heading="{{ $pageTitle }}" inline-size="base">

    {{-- Block 1 — settings --}}
    <s-section heading="الإعدادات">
        <div class="stack">
            <div id="settings-banner" class="banner-slot" aria-live="polite"></div>

            <div id="settings-loading" class="loading-line" role="status">
                <s-spinner accessibilityLabel="جاري التحميل…" size="base"></s-spinner>
                <s-text color="subdued">جاري التحميل…</s-text>
            </div>

            <form id="settings-form" class="stack" novalidate hidden>
                <s-switch
                    id="enabled"
                    label="متوقف"
                    data-label-on="العرض شغال"
                    data-label-off="متوقف"
                ></s-switch>

                <div class="field-narrow">
                    <s-number-field
                        id="percent"
                        label="نسبة الخصم على القطعة الفردية"
                        value="{{ $defaultPercent }}"
                        min="{{ $minPercent }}"
                        max="{{ $maxPercent }}"
                        step="1"
                        suffix="%"
                        inputMode="numeric"
                        data-min="{{ $minPercent }}"
                        data-max="{{ $maxPercent }}"
                        data-range-error="اكتب رقم صحيح من {{ $minPercent }} لـ {{ $maxPercent }}"
                    ></s-number-field>
                </div>

                <div id="discount-status" class="status-line">
                    <div data-status="active" hidden>
                        <s-text tone="success">الخصم موجود وشغال في شوبيفاي</s-text>
                        <s-link data-discount-link href="#" target="_top">افتح في الخصومات</s-link>
                    </div>
                    <div data-status="inactive" hidden>
                        <s-text tone="caution">الخصم موجود في شوبيفاي لكنه مش شغال دلوقتي</s-text>
                        <s-link data-discount-link href="#" target="_top">افتح في الخصومات</s-link>
                    </div>
                    <div data-status="missing" hidden>
                        <s-text tone="caution">الخصم غير موجود — اضغط حفظ لإنشائه</s-text>
                    </div>
                </div>

                <div class="actions">
                    <s-button id="save" variant="primary">حفظ</s-button>
                </div>
            </form>
        </div>
    </s-section>

    {{-- Block 2 — numbers --}}
    <s-section heading="الأرقام">
        <div class="stack">
            <div class="field-narrow">
                <s-select id="range" label="الفترة" value="{{ $defaultRange }}" data-default="{{ $defaultRange }}">
                    @foreach ($ranges as $days)
                        <s-option value="{{ $days }}"@if ($days === $defaultRange) selected @endif>{{ $days === 7 ? '7 أيام' : $days.' يوم' }}</s-option>
                    @endforeach
                </s-select>
            </div>

            <div id="analytics-banner" class="banner-slot" aria-live="polite"></div>

            <div id="analytics-loading" class="loading-line" role="status">
                <s-spinner accessibilityLabel="جاري التحميل…" size="base"></s-spinner>
                <s-text color="subdued">جاري التحميل…</s-text>
            </div>

            <div id="analytics-empty" class="empty" hidden>
                <s-text color="subdued">مفيش أوردرات في الفترة دي</s-text>
            </div>

            <div id="analytics-body" class="stack" hidden>
                <div class="tiles" id="tiles" data-percent-template="{{ $percentTemplate }}">
                    <div class="tile">
                        <div class="tile-label">أوردرات أخدت العرض</div>
                        <div class="tile-value" data-field="offerOrders">—</div>
                        <div class="tile-note"><span data-field="offerOrdersPct">—</span>% من كل الأوردرات</div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">هدايا</div>
                        <div class="tile-value" data-field="giftUnits">—</div>
                    </div>
                    <div class="tile">
                        <div class="tile-label" id="percent-tile-label">{{ $percentLabel }}</div>
                        <div class="tile-value" data-field="percentUnits">—</div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">إجمالي الخصم</div>
                        <div class="tile-value" data-field="totalDiscount">—</div>
                    </div>
                    <div class="tile tile-wide">
                        <div class="tile-label">السيناريو التاني كان هيدخل زيادة</div>
                        <div class="tile-value" data-field="scenario2Extra">—</div>
                        <div class="tile-note">إعادة حساب على نفس الأوردرات؛ الأوردرات ذات العدد الزوجي تساهم بصفر؛ السعر الأصلي من سعر المقارنة الحالي</div>
                    </div>
                </div>

                <p id="capped-note" class="note note-caution" hidden>محسوب على أول {{ $orderCap }} أوردر</p>

                <div id="top-gifts" class="stack-tight" hidden>
                    <h3 class="table-title">أكتر 10 منتجات خرجت هدايا</h3>
                    <s-table variant="auto">
                        <s-table-header-row>
                            <s-table-header listSlot="primary">المنتج</s-table-header>
                            <s-table-header listSlot="labeled" format="numeric">قطع</s-table-header>
                            <s-table-header listSlot="labeled" format="numeric">القيمة</s-table-header>
                        </s-table-header-row>
                        <s-table-body id="top-gifts-rows"></s-table-body>
                    </s-table>
                </div>
            </div>
        </div>
    </s-section>

</s-page>
</body>
</html>
