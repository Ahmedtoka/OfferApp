{{--
    The embedded admin document (English LTR or Arabic RTL, from Shopify's `locale` param).
    Home, Settings, Analytics and Test are client-side sections of this one page; app.js shows the one
    matching the URL path and the s-app-nav links.

    Polaris web components, verified 2026-10-31 against
      https://shopify.dev/docs/api/app-home/polaris-web-components
      https://shopify.dev/docs/api/app-home/app-bridge-web-components/app-nav   (s-app-nav with s-link children, rel="home")
    Tags used: s-app-nav, s-page, s-section, s-switch, s-number-field, s-text-field, s-select / s-option,
      s-button, s-banner, s-link, s-spinner, s-text, s-table*.

    Only public values are rendered here: the App Bridge api key, the shared offer constants and the page
    strings. The API secret and every access token stay on the server; app.js authenticates each /api call
    with a short-lived App Bridge session token.
--}}
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <title>{{ $pageTitle }}</title>
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris-2.js"></script>
    <script type="application/json" id="i18n">{!! $i18nJson !!}</script>
    <link rel="stylesheet" href="/app.css?v={{ $assetVersion }}">
    <script type="module" src="/app.js?v={{ $assetVersion }}"></script>
</head>
<body data-api-key="{{ $apiKey }}" data-theme-handle="{{ $themeHandle }}">

<s-app-nav>
    <s-link href="/" rel="home">{{ __('app.nav.home') }}</s-link>
    <s-link href="/settings">{{ __('app.nav.settings') }}</s-link>
    <s-link href="/analytics">{{ __('app.nav.analytics') }}</s-link>
    <s-link href="/test">{{ __('app.nav.test') }}</s-link>
</s-app-nav>

<s-page heading="{{ $pageTitle }}" inline-size="base">

    <div id="billing-banner" class="banner-slot" aria-live="polite"></div>
    <div id="billing-status" class="billing-status" role="status" hidden>
        <s-text id="billing-text"></s-text>
        <s-button id="billing-action" hidden></s-button>
    </div>

    {{-- Home: onboarding --}}
    <div id="page-home" data-page="home" hidden>
        <s-section heading="{{ __('app.home.heading') }}">
            <div class="stack">
                <s-text color="subdued">{{ __('app.home.intro') }}</s-text>
                <div id="checklist-progress" class="progress" role="status"></div>
                <ol id="checklist" class="checklist"></ol>
            </div>
        </s-section>
    </div>

    {{-- Settings --}}
    <div id="page-settings" data-page="settings" hidden>
        <s-section heading="{{ __('app.settings.heading') }}">
            <div class="stack">
                <div id="settings-banner" class="banner-slot" aria-live="polite"></div>

                <div id="settings-loading" class="loading-line" role="status">
                    <s-spinner accessibilityLabel="{{ __('app.common.loading') }}" size="base"></s-spinner>
                    <s-text color="subdued">{{ __('app.common.loading') }}</s-text>
                </div>

                <form id="settings-form" class="stack" novalidate hidden>
                    <div id="state-line" class="state-line" role="status">
                        <s-text id="state-text"></s-text>
                        <s-link id="discount-link" href="#" target="_top" hidden>{{ __('app.common.open_discount') }}</s-link>
                    </div>

                    <s-switch id="enabled" label="{{ __('app.settings.enabled_off') }}"></s-switch>

                    <div class="field-narrow">
                        <s-number-field
                            id="percent"
                            label="{{ __('app.settings.percent') }}"
                            value="{{ $defaultPercent }}"
                            min="{{ $minPercent }}"
                            max="{{ $maxPercent }}"
                            step="1"
                            suffix="%"
                            inputMode="numeric"
                            data-min="{{ $minPercent }}"
                            data-max="{{ $maxPercent }}"
                        ></s-number-field>
                    </div>

                    <div class="field-narrow">
                        <s-select id="method" label="{{ __('app.settings.method') }}" value="{{ $defaultMethod }}" data-default="{{ $defaultMethod }}">
                            @foreach ($methods as $method)
                                <s-option value="{{ $method }}"@if ($method === $defaultMethod) selected @endif>{{ __('app.settings.method_'.$method) }}</s-option>
                            @endforeach
                        </s-select>
                        <s-text color="subdued" id="method-help"></s-text>
                    </div>

                    <div class="field-narrow">
                        <s-select id="applies-to" label="{{ __('app.settings.applies_to') }}" value="tag">
                            <s-option value="tag" selected>{{ __('app.settings.applies_tag') }}</s-option>
                            <s-option value="collection">{{ __('app.settings.applies_collection') }}</s-option>
                        </s-select>
                    </div>

                    <div id="tag-group" class="field-narrow">
                        <s-text-field
                            id="eligible-tag"
                            label="{{ __('app.settings.tag') }}"
                            value=""
                            maxLength="{{ $tagMaxLength }}"
                            autocomplete="off"
                            data-max="{{ $tagMaxLength }}"
                        ></s-text-field>
                        <s-text color="subdued" id="eligible-tag-note"></s-text>
                    </div>

                    <div id="collection-group" class="stack-tight" hidden>
                        <div class="field-label">{{ __('app.settings.collection') }}</div>
                        <div class="inline">
                            <s-text id="collection-title">{{ __('app.settings.collection_none') }}</s-text>
                            <s-button id="collection-pick" variant="secondary">{{ __('app.settings.collection_choose') }}</s-button>
                        </div>
                        <s-text id="collection-error" tone="critical" hidden></s-text>
                    </div>

                    <div class="stack-tight">
                        <div class="dates">
                            <label class="date-field">
                                <span class="field-label">{{ __('app.settings.starts_at') }}</span>
                                <input id="starts-at" type="datetime-local">
                            </label>
                            <label class="date-field">
                                <span class="field-label">{{ __('app.settings.ends_at') }}</span>
                                <input id="ends-at" type="datetime-local">
                            </label>
                            <s-button id="clear-end" variant="tertiary">{{ __('app.settings.clear_end') }}</s-button>
                        </div>
                        <s-text id="dates-error" tone="critical" hidden></s-text>
                        <s-text id="dates-note" color="subdued" hidden></s-text>
                        <s-text color="subdued">{{ __('app.settings.dates_help') }}</s-text>
                    </div>

                    <div class="stack-tight">
                        <s-switch id="coupons" label="{{ __('app.settings.coupons') }}"></s-switch>
                        <s-text color="subdued">{{ __('app.settings.coupons_help') }}</s-text>
                    </div>

                    <div class="actions">
                        <s-button id="save" variant="primary">{{ __('app.common.save') }}</s-button>
                    </div>
                </form>
            </div>
        </s-section>
    </div>

    {{-- Analytics: layout A (one sentence, four numbers, details collapsed) --}}
    <div id="page-analytics" data-page="analytics" hidden>
        <s-section heading="{{ __('app.analytics.heading') }}">
            <div class="stack">
                <div class="field-narrow">
                    <s-select id="range" label="{{ __('app.analytics.period') }}" value="{{ $defaultRange }}" data-default="{{ $defaultRange }}">
                        @foreach ($ranges as $days)
                            <s-option value="{{ $days }}"@if ($days === $defaultRange) selected @endif>{{ str_replace('{n}', (string) $days, __('app.analytics.days')) }}</s-option>
                        @endforeach
                    </s-select>
                </div>

                <div id="analytics-banner" class="banner-slot" aria-live="polite"></div>

                <div id="analytics-scope" class="stack-tight" hidden>
                    <s-text>{{ __('app.analytics.orders_unlocks') }}</s-text>
                    <s-text color="subdued">{{ __('app.analytics.limit_60') }}</s-text>
                    <div><s-button id="orders-grant" variant="primary">{{ __('app.analytics.orders_button') }}</s-button></div>
                </div>

                <div id="analytics-loading" class="loading-line" role="status" hidden>
                    <s-spinner accessibilityLabel="{{ __('app.common.loading') }}" size="base"></s-spinner>
                    <s-text color="subdued">{{ __('app.common.loading') }}</s-text>
                </div>

                <div id="analytics-empty" class="empty" hidden>
                    <s-text color="subdued">{{ __('app.analytics.empty') }}</s-text>
                </div>

                <div id="analytics-body" class="stack" hidden>
                    <p id="analytics-summary" class="summary"></p>

                    <div class="tiles" id="tiles">
                        <div class="tile">
                            <div class="tile-label">{{ __('app.analytics.tile_sales') }}</div>
                            <div class="tile-value" data-field="sales">—</div>
                            <div class="tile-note" data-field="sales-note"></div>
                        </div>
                        <div class="tile">
                            <div class="tile-label">{{ __('app.analytics.tile_discount') }}</div>
                            <div class="tile-value" data-field="discount">—</div>
                            <div class="tile-note" data-field="discount-note"></div>
                        </div>
                        <div class="tile">
                            <div class="tile-label">{{ __('app.analytics.tile_aov') }}</div>
                            <div class="tile-value" data-field="aov">—</div>
                            <div class="tile-note" data-field="aov-note"></div>
                        </div>
                        <div class="tile">
                            <div class="tile-label">{{ __('app.analytics.tile_units') }}</div>
                            <div class="tile-value" data-field="units">—</div>
                            <div class="tile-note" data-field="units-note"></div>
                        </div>
                    </div>

                    <p class="note-subdued">{{ __('app.analytics.basis') }}</p>
                    <p class="note-subdued">{{ __('app.analytics.limit_60') }}</p>
                    <p id="analytics-updated" class="note-subdued"></p>
                    <p id="capped-note" class="note note-caution" data-count="{{ $orderCap }}" hidden></p>

                    <details class="more">
                        <summary>{{ __('app.analytics.details_gifts') }}</summary>
                        <div class="more-body stack-tight">
                            <ul class="facts">
                                <li><span>{{ __('app.analytics.gift_units') }}</span> <strong class="num" data-field="giftUnits"></strong></li>
                                <li><span id="percent-units-label"></span> <strong class="num" data-field="percentUnits"></strong></li>
                                <li><span>{{ __('app.analytics.share') }}</span> <strong class="num" data-field="share"></strong></li>
                            </ul>
                            <div id="top-gifts" class="stack-tight">
                                <h3 class="table-title">{{ __('app.analytics.top_gifts') }}</h3>
                                <s-table variant="auto">
                                    <s-table-header-row>
                                        <s-table-header listSlot="primary">{{ __('app.analytics.product') }}</s-table-header>
                                        <s-table-header listSlot="labeled" format="numeric">{{ __('app.analytics.pieces') }}</s-table-header>
                                        <s-table-header listSlot="labeled" format="numeric">{{ __('app.analytics.value') }}</s-table-header>
                                    </s-table-header-row>
                                    <s-table-body id="top-gifts-rows"></s-table-body>
                                </s-table>
                            </div>
                            <s-text id="no-gifts" color="subdued" hidden>{{ __('app.analytics.no_gifts') }}</s-text>
                        </div>
                    </details>

                    <details class="more">
                        <summary>{{ __('app.analytics.details_other') }}</summary>
                        <div class="more-body"><s-text id="other-method"></s-text></div>
                    </details>

                    <details class="more">
                        <summary>{{ __('app.analytics.details_profit') }}</summary>
                        <div class="more-body stack-tight">
                            <s-text id="profit-text"></s-text>
                            <s-text id="profit-covered" color="subdued" hidden></s-text>
                            <div id="profit-grant" class="stack-tight" hidden>
                                <s-text color="subdued">{{ __('app.analytics.profit_unlocks') }}</s-text>
                                <div><s-button id="inventory-grant" variant="secondary">{{ __('app.analytics.profit_button') }}</s-button></div>
                            </div>
                        </div>
                    </details>
                </div>
            </div>
        </s-section>
    </div>

    {{-- Test: sample cart, both methods side by side --}}
    <div id="page-test" data-page="test" hidden>
        <s-section heading="{{ __('app.test.heading') }}">
            <div class="stack">
                <s-text color="subdued">{{ __('app.test.intro') }}</s-text>
                <s-text id="test-saved" color="subdued"></s-text>
                <div id="test-banner" class="banner-slot" aria-live="polite"></div>

                <div class="inline">
                    <s-button id="test-pick" variant="secondary">{{ __('app.test.pick') }}</s-button>
                </div>

                <div class="stack-tight">
                    <div class="field-label">{{ __('app.test.typed') }}</div>
                    <div class="dates">
                        <s-text-field id="typed-title" label="{{ __('app.test.title') }}" value="" maxLength="255" autocomplete="off"></s-text-field>
                        <s-text-field id="typed-price" label="{{ __('app.test.price') }}" value="" inputMode="decimal" autocomplete="off"></s-text-field>
                        <s-button id="typed-add" variant="secondary">{{ __('app.test.add') }}</s-button>
                    </div>
                </div>

                <s-text color="subdued" id="test-empty">{{ __('app.test.empty') }}</s-text>
                <ul id="test-lines" class="test-lines" hidden></ul>

                <div class="actions">
                    <s-button id="test-run" variant="primary" disabled>{{ __('app.test.run') }}</s-button>
                </div>

                <div id="test-result" class="stack" hidden></div>
            </div>
        </s-section>
    </div>

</s-page>
</body>
</html>
