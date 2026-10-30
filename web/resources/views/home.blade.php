{{--
    Le Voile BOGO — the single embedded admin page (English, LTR).

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
<html lang="en" dir="ltr">
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
    <s-section heading="Settings">
        <div class="stack">
            <div id="settings-banner" class="banner-slot" aria-live="polite"></div>

            <div id="settings-loading" class="loading-line" role="status">
                <s-spinner accessibilityLabel="Loading…" size="base"></s-spinner>
                <s-text color="subdued">Loading…</s-text>
            </div>

            <form id="settings-form" class="stack" novalidate hidden>
                <s-switch
                    id="enabled"
                    label="Offer is OFF"
                    data-label-on="Offer is ON"
                    data-label-off="Offer is OFF"
                ></s-switch>

                <div class="field-narrow">
                    <s-number-field
                        id="percent"
                        label="Discount on a single piece (%)"
                        value="{{ $defaultPercent }}"
                        min="{{ $minPercent }}"
                        max="{{ $maxPercent }}"
                        step="1"
                        suffix="%"
                        inputMode="numeric"
                        data-min="{{ $minPercent }}"
                        data-max="{{ $maxPercent }}"
                        data-range-error="Enter a whole number from {{ $minPercent }} to {{ $maxPercent }}"
                    ></s-number-field>
                    <s-text color="subdued" id="eligible-tag-note">Applies only to products tagged "{{ $eligibleTag }}".</s-text>
                </div>

                <div id="discount-status" class="status-line">
                    <div data-status="active" hidden>
                        <s-text tone="success">Discount exists and is active in Shopify</s-text>
                        <s-link data-discount-link href="#" target="_top">Open in Discounts</s-link>
                    </div>
                    <div data-status="inactive" hidden>
                        <s-text tone="caution">Discount exists in Shopify but is not active right now</s-text>
                        <s-link data-discount-link href="#" target="_top">Open in Discounts</s-link>
                    </div>
                    <div data-status="missing" hidden>
                        <s-text tone="caution">No discount yet — click Save to create it</s-text>
                    </div>
                </div>

                <div class="actions">
                    <s-button id="save" variant="primary">Save</s-button>
                </div>
            </form>
        </div>
    </s-section>

    {{-- Block 2 — numbers --}}
    <s-section heading="Numbers">
        <div class="stack">
            <div class="field-narrow">
                <s-select id="range" label="Period" value="{{ $defaultRange }}" data-default="{{ $defaultRange }}">
                    @foreach ($ranges as $days)
                        <s-option value="{{ $days }}"@if ($days === $defaultRange) selected @endif>{{ $days === 7 ? '7 days' : $days.' days' }}</s-option>
                    @endforeach
                </s-select>
            </div>

            <div id="analytics-banner" class="banner-slot" aria-live="polite"></div>

            <div id="analytics-loading" class="loading-line" role="status">
                <s-spinner accessibilityLabel="Loading…" size="base"></s-spinner>
                <s-text color="subdued">Loading…</s-text>
            </div>

            <div id="analytics-empty" class="empty" hidden>
                <s-text color="subdued">No orders in this period</s-text>
            </div>

            <div id="analytics-body" class="stack" hidden>
                <div class="tiles" id="tiles" data-percent-template="{{ $percentTemplate }}">
                    <div class="tile">
                        <div class="tile-label">Orders with the offer</div>
                        <div class="tile-value" data-field="offerOrders">—</div>
                        <div class="tile-note"><span data-field="offerOrdersPct">—</span>% of all orders</div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Gifts</div>
                        <div class="tile-value" data-field="giftUnits">—</div>
                    </div>
                    <div class="tile">
                        <div class="tile-label" id="percent-tile-label">{{ $percentLabel }}</div>
                        <div class="tile-value" data-field="percentUnits">—</div>
                    </div>
                    <div class="tile">
                        <div class="tile-label">Total discount</div>
                        <div class="tile-value" data-field="totalDiscount">—</div>
                    </div>
                    <div class="tile tile-wide">
                        <div class="tile-label">Scenario 2 would have earned extra</div>
                        <div class="tile-value" data-field="scenario2Extra">—</div>
                        <div class="tile-note">Recalculated on the same orders; even-count orders contribute 0; original price = current compare-at price. Refunded orders are counted as granted; cancelled orders are excluded.</div>
                    </div>
                </div>

                <p id="capped-note" class="note note-caution" hidden>Computed on the first {{ number_format($orderCap) }} orders</p>

                <div id="top-gifts" class="stack-tight" hidden>
                    <h3 class="table-title">Top 10 gifted products</h3>
                    <s-table variant="auto">
                        <s-table-header-row>
                            <s-table-header listSlot="primary">Product</s-table-header>
                            <s-table-header listSlot="labeled" format="numeric">Pieces</s-table-header>
                            <s-table-header listSlot="labeled" format="numeric">Value</s-table-header>
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
