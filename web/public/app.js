// Pairly admin page. Every /api call carries a fresh App Bridge session token; nothing secret is ever
// read from or written to the page. All user-facing text comes from the server (the #i18n JSON block).

import { toIso, fromIso, stateKey, checklist, themeEditorUrl, isShopHost } from './js/lib.js';
import { summarySentence } from './js/summary.js';
import { billingView } from './js/billing.js';

const $ = (sel) => document.querySelector(sel);

// ---------------------------------------------------------------- strings

const i18n = JSON.parse($('#i18n').textContent);
const LOCALE = i18n.locale;

function t(key, vars = {}) {
    let text = key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), i18n.t);
    if (typeof text !== 'string') return key;
    for (const [name, value] of Object.entries(vars)) {
        text = text.split(`{${name}}`).join(String(value));
    }
    return text;
}

const GENERIC_ERROR = t('errors.generic');

// ---------------------------------------------------------------- storage (per-viewer conveniences only)

function readStore(key) {
    try {
        const value = JSON.parse(localStorage.getItem(key) ?? 'null');
        return value && typeof value === 'object' ? value : {};
    } catch {
        return {};
    }
}

function writeStore(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // storage blocked: the page still works, the mark just does not persist
    }
}

const MARKS_KEY = 'pairly.checklist';

// ---------------------------------------------------------------- elements

const els = {
    billingBanner: $('#billing-banner'),
    billingStatus: $('#billing-status'),
    billingText: $('#billing-text'),
    billingAction: $('#billing-action'),
    checklist: $('#checklist'),
    checklistProgress: $('#checklist-progress'),
    settingsBanner: $('#settings-banner'),
    settingsLoading: $('#settings-loading'),
    form: $('#settings-form'),
    stateText: $('#state-text'),
    discountLink: $('#discount-link'),
    enabled: $('#enabled'),
    percent: $('#percent'),
    method: $('#method'),
    methodHelp: $('#method-help'),
    appliesTo: $('#applies-to'),
    tagGroup: $('#tag-group'),
    tag: $('#eligible-tag'),
    tagNote: $('#eligible-tag-note'),
    collectionGroup: $('#collection-group'),
    collectionTitle: $('#collection-title'),
    collectionPick: $('#collection-pick'),
    collectionError: $('#collection-error'),
    startsAt: $('#starts-at'),
    endsAt: $('#ends-at'),
    clearEnd: $('#clear-end'),
    datesError: $('#dates-error'),
    datesNote: $('#dates-note'),
    coupons: $('#coupons'),
    save: $('#save'),
    range: $('#range'),
    analyticsBanner: $('#analytics-banner'),
    analyticsLoading: $('#analytics-loading'),
    analyticsEmpty: $('#analytics-empty'),
    analyticsBody: $('#analytics-body'),
    analyticsScope: $('#analytics-scope'),
    ordersGrant: $('#orders-grant'),
    inventoryGrant: $('#inventory-grant'),
    analyticsSummary: $('#analytics-summary'),
    analyticsUpdated: $('#analytics-updated'),
    tiles: $('#tiles'),
    percentUnitsLabel: $('#percent-units-label'),
    capped: $('#capped-note'),
    topGifts: $('#top-gifts'),
    topGiftsRows: $('#top-gifts-rows'),
    noGifts: $('#no-gifts'),
    otherMethod: $('#other-method'),
    profitText: $('#profit-text'),
    profitCovered: $('#profit-covered'),
    profitGrant: $('#profit-grant'),
    testSaved: $('#test-saved'),
    testBanner: $('#test-banner'),
    testPick: $('#test-pick'),
    typedTitle: $('#typed-title'),
    typedPrice: $('#typed-price'),
    typedAdd: $('#typed-add'),
    testEmpty: $('#test-empty'),
    testLines: $('#test-lines'),
    testRun: $('#test-run'),
    testResult: $('#test-result'),
};

// ---------------------------------------------------------------- formatting

const intFmt = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });
const pctFmt = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 1 });
const dateFmt = new Intl.DateTimeFormat(LOCALE, { dateStyle: 'medium', timeStyle: 'short' });

const formatCount = (n) => intFmt.format(Number(n) || 0);
const formatPct = (n) => pctFmt.format(Number(n) || 0);

function formatMoney(n, currency) {
    const amount = Number(n) || 0;
    try {
        return new Intl.NumberFormat(LOCALE, { style: 'currency', currency }).format(amount);
    } catch {
        return new Intl.NumberFormat(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
    }
}

function formatDate(iso) {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : dateFmt.format(d);
}

// ---------------------------------------------------------------- API

class ApiError extends Error {
    constructor(message, status = 0) {
        super(message);
        this.status = status;
    }
}

// A dev-tunnel restart can make the first load fail transiently; GETs get one retry (never PUT: no double save).
const RETRY_DELAY_MS = 800;
const RETRYABLE_STATUSES = [401, 502, 503, 504];

class RetryableError extends ApiError {}

async function api(method, path, body) {
    try {
        return await apiOnce(method, path, body);
    } catch (e) {
        if (method !== 'GET' || !(e instanceof RetryableError)) {
            throw e;
        }
        await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY_MS));
        return apiOnce(method, path, body); // fresh shopify.idToken() inside
    }
}

async function apiOnce(method, path, body) {
    let res;
    try {
        const token = await shopify.idToken();
        res = await fetch(path, {
            method,
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
    } catch {
        throw new RetryableError(GENERIC_ERROR);
    }

    let data = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }

    if (res.status !== 200) {
        const serverError = data && typeof data.error === 'string' && data.error !== '' ? data.error : null;
        if (serverError === null && RETRYABLE_STATUSES.includes(res.status)) {
            throw new RetryableError(GENERIC_ERROR);
        }
        // 402 / 409 / 422 carry our or Shopify's message, shown verbatim; anything else without one is generic.
        throw new ApiError(serverError ?? GENERIC_ERROR, res.status);
    }

    return data ?? {};
}

// ---------------------------------------------------------------- UI helpers

function showBanner(slot, tone, text, action = null) {
    const banner = document.createElement('s-banner');
    banner.setAttribute('tone', tone);
    if (tone === 'success') {
        banner.setAttribute('dismissible', '');
        banner.addEventListener('afterhide', () => banner.remove());
    }
    banner.textContent = text;
    if (action) {
        const button = document.createElement('s-button');
        button.setAttribute('slot', 'secondary-actions');
        button.textContent = action.label;
        button.addEventListener('click', action.onClick);
        banner.append(button);
    }
    slot.replaceChildren(banner);
    return banner;
}

const clearBanner = (slot) => slot.replaceChildren();

function setError(el, message) {
    el.textContent = message ?? '';
    el.hidden = !message;
}

// ---------------------------------------------------------------- navigation (client-side pages)

const PAGES = ['home', 'settings', 'analytics', 'test'];
const PATHS = { home: '/', settings: '/settings', analytics: '/analytics', test: '/test' };
let currentPage = null;
let analyticsLoaded = false;

function pageFromPath(pathname) {
    const clean = pathname.replace(/\/+$/, '') || '/';
    return PAGES.find((p) => PATHS[p] === clean) ?? 'home';
}

function showPage(page) {
    currentPage = page;
    for (const name of PAGES) {
        $(`#page-${name}`).hidden = name !== page;
    }
    if (page === 'analytics' && !analyticsLoaded) {
        analyticsLoaded = true;
        loadAnalytics();
    }
}

function goTo(page) {
    const target = new URL(PATHS[page], location.href);
    target.search = location.search; // keep shop / host / locale
    if (window.navigation) {
        window.navigation.navigate(target.href);
    } else {
        history.pushState({}, '', target.href);
        showPage(page);
    }
}

if (window.navigation) {
    window.navigation.addEventListener('navigate', (event) => {
        const url = new URL(event.destination.url);
        if (url.origin !== location.origin || !event.canIntercept || event.hashChange || event.downloadRequest !== null) return;
        const page = pageFromPath(url.pathname);
        if (PATHS[page] !== (url.pathname.replace(/\/+$/, '') || '/')) return; // not one of ours
        event.intercept({ handler: async () => showPage(page) });
    });
}
window.addEventListener('popstate', () => showPage(pageFromPath(location.pathname)));

// ---------------------------------------------------------------- Settings

let loaded = null; // the last /api/settings payload

function setSwitchLabel() {
    const on = Boolean(els.enabled.checked);
    els.enabled.setAttribute('label', t(on ? 'settings.enabled_on' : 'settings.enabled_off'));
}

function renderTagNote() {
    els.tagNote.textContent = t('settings.tag_note', { tag: String(els.tag.value ?? '').trim() });
}

function renderMethodHelp() {
    const method = String(els.method.value || els.method.dataset.default);
    els.methodHelp.textContent = t(`settings.method_${method}_help`);
}

function renderAppliesTo() {
    const collection = els.appliesTo.value === 'collection';
    els.tagGroup.hidden = collection;
    els.collectionGroup.hidden = !collection;
}

let collectionId = null;

function renderCollection(title, missing) {
    els.collectionPick.textContent = t(collectionId === null ? 'settings.collection_choose' : 'settings.collection_change');
    if (collectionId === null) {
        els.collectionTitle.textContent = t('settings.collection_none');
    } else if (typeof title === 'string' && title !== '') {
        els.collectionTitle.textContent = title;
    } else {
        // Deleted in Shopify, or the lookup failed: say so only when the server confirmed it is gone.
        els.collectionTitle.textContent = t('settings.collection_none');
        if (missing) setError(els.collectionError, t('settings.collection_missing'));
    }
}

async function pickCollection() {
    try {
        const picked = await shopify.resourcePicker({ type: 'collection',
            multiple: false,
            selectionIds: collectionId === null ? [] : [{ id: collectionId }],
        });
        const item = Array.isArray(picked) ? picked[0] : null;
        if (!item || typeof item.id !== 'string') return;
        collectionId = item.id;
        setError(els.collectionError, null);
        renderCollection(String(item.title ?? ''), false);
    } catch {
        // the picker was closed or could not open; nothing to change
    }
}

function renderState(data) {
    const state = stateKey(data.state);
    const discount = data.discount ?? null;
    const settings = data.settings ?? {};
    let text;
    if (state === 'off') {
        text = t(discount ? 'state.off' : 'state.off_missing');
    } else if (state === 'scheduled') {
        text = t('state.scheduled', { date: formatDate(settings.startsAt ?? discount?.startsAt) });
    } else if (state === 'ended') {
        text = t('state.ended', { date: formatDate(settings.endsAt ?? discount?.endsAt) });
    } else {
        const end = settings.endsAt ?? discount?.endsAt;
        text = end ? t('state.running_until', { date: formatDate(end) }) : t('state.running');
    }
    els.stateText.textContent = text;
    els.stateText.setAttribute('tone', state === 'running' ? 'success' : state === 'off' ? 'neutral' : 'caution');
    if (discount && discount.adminUrl) {
        els.discountLink.setAttribute('href', discount.adminUrl);
        els.discountLink.hidden = false;
    } else {
        els.discountLink.hidden = true;
    }
}

// The plan state. Loaded on its own (GET /api/billing) so the page shows it whatever the settings say.
let billing = null;
let endedByBilling = false;

function renderBilling() {
    const view = billingView(billing);
    els.billingStatus.hidden = view.text === null;
    els.billingText.textContent = view.text === null ? '' : t(view.text, view.vars);
    els.billingAction.hidden = view.action === null;
    if (view.action !== null) {
        els.billingAction.textContent = t(view.action === 'subscribe' ? 'billing.subscribe' : 'billing.test_subscribe');
    }

    // The "subscription ended" banner shows only while the shop is not covered.
    const current = els.billingBanner.firstElementChild;
    if (endedByBilling && !view.hideEnded) {
        if (!current) showBanner(els.billingBanner, 'warning', t('billing.ended'), { label: t('billing.subscribe'), onClick: subscribe }).dataset.kind = 'ended';
    } else if (current?.dataset.kind === 'ended' && view.hideEnded) {
        clearBanner(els.billingBanner);
    }
}

async function loadBilling() {
    try {
        billing = await api('GET', '/api/billing');
    } catch {
        billing = null; // the settings page keeps working; saving still reports a 402
    }
    renderBilling();
}

els.billingAction.addEventListener('click', () => subscribe());

function renderBillingEnded(settings) {
    endedByBilling = settings.disabledReason === 'billing';
    renderBilling();
}

function renderSettings(data) {
    loaded = data;
    const settings = data.settings ?? {};
    els.enabled.checked = Boolean(settings.enabled);
    setSwitchLabel();
    if (Number.isInteger(settings.percent)) els.percent.value = String(settings.percent);
    if (typeof settings.method === 'string') els.method.value = settings.method;
    renderMethodHelp();
    els.appliesTo.value = settings.appliesTo === 'collection' ? 'collection' : 'tag';
    renderAppliesTo();
    els.tag.value = typeof settings.eligibleTag === 'string' ? settings.eligibleTag : '';
    els.tag.removeAttribute('error');
    renderTagNote();
    collectionId = typeof settings.collectionId === 'string' && settings.collectionId !== '' ? settings.collectionId : null;
    setError(els.collectionError, null);
    renderCollection(data.collectionTitle, Boolean(data.collectionMissing));
    els.startsAt.value = fromIso(settings.startsAt);
    els.endsAt.value = fromIso(settings.endsAt);
    renderEndNote();
    setError(els.datesError, null);
    els.coupons.checked = Boolean(settings.combineWithCoupons);
    renderState(data);
    renderChecklist();
    renderPercentLabel();
    renderTestSaved();
}

async function loadSettings() {
    try {
        const data = await api('GET', '/api/settings');
        renderSettings(data);
        renderBillingEnded(data.settings ?? {});
        els.form.hidden = false;
    } catch (e) {
        showBanner(els.settingsBanner, 'critical', e.message);
        els.checklistProgress.textContent = '';
    } finally {
        els.settingsLoading.hidden = true;
    }
}

function readPercent() {
    const raw = String(els.percent.value ?? '').trim();
    const min = Number(els.percent.dataset.min);
    const max = Number(els.percent.dataset.max);
    const n = /^\d+$/.test(raw) ? Number(raw) : NaN;
    if (!Number.isInteger(n) || n < min || n > max) {
        els.percent.setAttribute('error', t('settings.percent_error', { min, max }));
        return null;
    }
    els.percent.removeAttribute('error');
    return n;
}

// Same rules as the server: required after trimming, at most data-max chars, no commas
// (Shopify splits product tags on commas).
function readTag() {
    const tag = String(els.tag.value ?? '').trim();
    let error = null;
    if (tag === '') error = t('settings.tag_required');
    else if (tag.length > Number(els.tag.dataset.max)) error = t('settings.tag_length', { max: els.tag.dataset.max });
    else if (tag.includes(',')) error = t('settings.tag_comma');
    if (error !== null) {
        els.tag.setAttribute('error', error);
        return null;
    }
    els.tag.removeAttribute('error');
    return tag;
}

// With the offer off the server keeps a saved end date (it only clears it once the offer is on): say so.
function renderEndNote() {
    const kept = Boolean(loaded?.settings?.endsAt) && els.endsAt.value === '' && !els.enabled.checked;
    els.datesNote.textContent = kept ? t('settings.end_clear_note') : '';
    els.datesNote.hidden = !kept;
}

function readDates() {
    const startsAt = toIso(els.startsAt.value);
    const endsAt = toIso(els.endsAt.value);
    if (startsAt !== null && endsAt !== null && new Date(endsAt) <= new Date(startsAt)) {
        setError(els.datesError, t('settings.ends_before_start'));
        return null;
    }
    setError(els.datesError, null);
    return { startsAt, endsAt };
}

async function subscribe() {
    clearBanner(els.settingsBanner);
    try {
        const data = await api('POST', '/api/billing/subscribe');
        if (typeof data.confirmationUrl === 'string' && data.confirmationUrl !== '') {
            open(data.confirmationUrl, '_top'); // Shopify's approval page must load at top level
            return true;
        }
    } catch (e) {
        if (e.status === 409) {
            // Already subscribed: the page was out of date, so show the real state.
            await loadBilling();
            showBanner(els.billingBanner, 'success', t('billing.already'));
        } else {
            showBanner(els.billingBanner, 'critical', e.message);
        }
    }
    return false;
}

let saving = false;

async function saveOffThenSubscribe(payload) {
    try {
        const data = await api('PUT', '/api/settings', { ...payload, enabled: false });
        renderSettings(data);
    } catch (e) {
        showBanner(els.settingsBanner, 'critical', e.message);
        return;
    }
    showBanner(els.billingBanner, 'warning', t('billing.saved_off_redirect'));
    await subscribe();
}

async function save() {
    if (saving) return;
    const tagMode = els.appliesTo.value !== 'collection';
    const percent = readPercent();
    const eligibleTag = tagMode ? readTag() : '';
    const dates = readDates();
    let collectionOk = true;
    if (!tagMode && collectionId === null) {
        setError(els.collectionError, t('settings.collection_required'));
        collectionOk = false;
    } else {
        setError(els.collectionError, null);
    }
    if (percent === null || eligibleTag === null || dates === null || !collectionOk) return;

    saving = true;
    clearBanner(els.settingsBanner);
    clearBanner(els.billingBanner);
    els.save.setAttribute('loading', '');
    els.save.setAttribute('disabled', '');
    const payload = {
        enabled: Boolean(els.enabled.checked),
        percent,
        method: String(els.method.value || els.method.dataset.default),
        appliesTo: tagMode ? 'tag' : 'collection',
        eligibleTag: tagMode ? eligibleTag : null,
        collectionId: tagMode ? null : collectionId,
        combineWithCoupons: Boolean(els.coupons.checked),
        startsAt: dates.startsAt,
        endsAt: dates.endsAt,
    };
    try {
        const data = await api('PUT', '/api/settings', payload);
        renderSettings(data);
        showBanner(els.settingsBanner, 'success', typeof data.message === 'string' && data.message !== '' ? data.message : t('settings.saved_fallback'));
    } catch (e) {
        if (e.status === 402) {
            // The offer needs a plan. Keep everything the merchant typed: save it with the offer off, then go to approval.
            await saveOffThenSubscribe(payload);
        } else {
            showBanner(els.settingsBanner, 'critical', e.message);
        }
    } finally {
        saving = false;
        els.save.removeAttribute('loading');
        els.save.removeAttribute('disabled');
    }
}

// ---------------------------------------------------------------- Home (onboarding)

function shopDomain() {
    let host = '';
    try {
        if (typeof shopify.config?.shop === 'string') host = shopify.config.shop;
    } catch {
        // fall through to the query string
    }
    if (!isShopHost(host)) host = new URLSearchParams(location.search).get('shop') ?? '';
    return isShopHost(host) ? host : '';
}

function stepAction(id) {
    if (id === 'test') return () => goTo('test');
    if (id === 'theme') {
        return () => {
            const shop = shopDomain();
            if (shop !== '') open(themeEditorUrl(shop, document.body.dataset.apiKey, document.body.dataset.themeHandle), '_blank');
        };
    }
    return () => goTo('settings');
}

function renderChecklist() {
    if (!loaded) return;
    const marks = readStore(MARKS_KEY);
    const steps = checklist(loaded, marks);
    const done = steps.filter((s) => s.done).length;
    els.checklistProgress.textContent = t('home.progress', { done: formatCount(done), total: formatCount(steps.length) });

    els.checklist.replaceChildren(
        ...steps.map((step, index) => {
            const li = document.createElement('li');
            li.className = `step${step.done ? ' is-done' : ''}`;

            const mark = document.createElement('span');
            mark.className = 'step-mark';
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = step.done ? '✓' : formatCount(index + 1);

            const text = document.createElement('div');
            text.className = 'step-text';
            const title = document.createElement('strong');
            title.textContent = t(`home.steps.${step.id}.title`);
            const body = document.createElement('div');
            body.className = 'step-body';
            body.textContent = t(`home.steps.${step.id}.body`);
            text.append(title, body);

            const actions = document.createElement('div');
            actions.className = 'step-actions';
            const go = document.createElement('s-button');
            go.setAttribute('variant', step.done ? 'tertiary' : 'secondary');
            go.textContent = t(`home.steps.${step.id}.action`);
            go.addEventListener('click', stepAction(step.id));
            actions.append(go);

            if (step.id === 'test' || step.id === 'theme') {
                const toggle = document.createElement('s-button');
                toggle.setAttribute('variant', 'tertiary');
                toggle.textContent = t(step.done ? 'home.mark_undone' : 'home.mark_done');
                toggle.addEventListener('click', () => {
                    const next = readStore(MARKS_KEY);
                    next[step.id] = !step.done;
                    writeStore(MARKS_KEY, next);
                    renderChecklist();
                });
                actions.append(toggle);
            }

            li.append(mark, text, actions);
            return li;
        }),
    );
}

// ---------------------------------------------------------------- Analytics (layout A)

let analyticsSeq = 0;
let hasAnalytics = false;
let lastAnalytics = null;

function setField(name, text) {
    for (const el of els.analyticsBody.querySelectorAll(`[data-field="${name}"]`)) {
        el.textContent = text;
    }
}

function cell(text, numeric = false) {
    const c = document.createElement('s-table-cell');
    if (numeric) {
        const span = document.createElement('span');
        span.className = 'num';
        span.textContent = text;
        c.append(span);
    } else {
        c.textContent = text;
    }
    return c;
}

/** The current percent label comes from the saved settings; it is filled again when they arrive after the analytics. */
function renderPercentLabel() {
    if (loaded && Number.isInteger(loaded.settings?.percent)) {
        els.percentUnitsLabel.textContent = t('analytics.percent_units', { pct: loaded.settings.percent });
    } else {
        els.percentUnitsLabel.textContent = t('analytics.percent_units_plain');
    }
}

function renderAverages(a, currency) {
    const units = (n, orders) => (orders > 0 ? n / orders : null);
    const unitsFmt = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 1 });
    const offerUnits = units(a.unitsOffer, a.offerOrders);
    const otherOrders = a.ordersTotal - a.offerOrders;
    const otherUnits = units(a.unitsOther, otherOrders);

    setField('aov', a.aovOffer === null ? '—' : formatMoney(a.aovOffer, currency));
    setField('aov-note', a.aovOther === null ? t('analytics.tile_aov_none') : t('analytics.tile_aov_note', { other: formatMoney(a.aovOther, currency) }));
    setField('units', offerUnits === null ? '—' : unitsFmt.format(offerUnits));
    setField('units-note', otherUnits === null ? t('analytics.tile_units_none') : t('analytics.tile_units_note', { other: unitsFmt.format(otherUnits) }));
}

function renderOtherMethod(a, currency) {
    const other = a.otherMethod ?? {};
    const method = ['customer', 'company'].includes(other.method) ? other.method : 'customer';
    const vars = { method: t(`settings.method_${method}`), amount: formatMoney(other.difference, currency) };
    els.otherMethod.textContent = Number(other.difference) > 0 ? t(`analytics.other_${method}`, vars) : t('analytics.other_none', vars);
}

function renderProfit(a, currency) {
    const profit = a.profit ?? null;
    if (profit) {
        els.profitText.textContent = t('analytics.profit_amount', { amount: formatMoney(profit.amount, currency) });
        els.profitCovered.textContent = t('analytics.profit_covered', { pct: formatPct(profit.coveredPct) });
        els.profitCovered.hidden = false;
        els.profitGrant.hidden = true;
        return;
    }
    els.profitText.textContent = t('analytics.profit_none');
    els.profitCovered.hidden = true;
    els.profitGrant.hidden = false; // hidden again below if the scope turns out to be granted already
    grantedScopes().then((granted) => {
        if (granted.includes('read_inventory') && lastAnalytics === a) els.profitGrant.hidden = true;
    });
}

async function grantedScopes() {
    try {
        const detail = await shopify.scopes.query();
        return Array.isArray(detail?.granted) ? detail.granted : [];
    } catch {
        return [];
    }
}

/** Optional scopes are asked for with App Bridge (shopify.dev: App Bridge scopes API); the merchant sees Shopify's own dialog. */
async function requestScope(scope, slot) {
    try {
        const response = await shopify.scopes.request([scope]);
        if (response?.result === 'granted-all') return true;
        showBanner(slot, 'warning', t('analytics.scope_declined'));
    } catch {
        showBanner(slot, 'critical', t('analytics.scope_error'));
    }
    return false;
}

function renderAnalytics(a) {
    lastAnalytics = a;
    els.analyticsScope.hidden = true;
    if ((Number(a.ordersTotal) || 0) === 0) {
        els.analyticsBody.hidden = true;
        els.analyticsEmpty.hidden = false;
        return;
    }

    const currency = typeof a.currency === 'string' ? a.currency : '';
    els.analyticsSummary.textContent = summarySentence(a, t, formatPct);

    setField('sales', formatMoney(a.offerSales, currency));
    setField('sales-note', t('analytics.tile_sales_note', { n: formatCount(a.offerOrders) }));
    setField('discount', formatMoney(a.totalDiscount, currency));
    setField('discount-note', t('analytics.tile_discount_note', { pct: formatPct(a.discountPctOfSales) }));
    renderAverages(a, currency);

    const computed = formatDate(a.computedAt);
    els.analyticsUpdated.textContent = computed === '' ? '' : t('analytics.updated', { when: computed });
    els.capped.textContent = t('analytics.capped', { n: formatCount(els.capped.dataset.count) });
    els.capped.hidden = !a.capped;

    setField('giftUnits', formatCount(a.giftUnits));
    setField('percentUnits', formatCount(a.percentUnits));
    setField('share', t('analytics.of_all_orders', { pct: formatPct(a.offerOrdersPct) }));
    renderPercentLabel();

    const gifts = Array.isArray(a.topGifts) ? a.topGifts.slice(0, 10) : [];
    els.topGiftsRows.replaceChildren(
        ...gifts.map((g) => {
            const row = document.createElement('s-table-row');
            row.append(cell(String(g.title ?? '')), cell(formatCount(g.units), true), cell(formatMoney(g.value, currency), true));
            return row;
        }),
    );
    els.topGifts.hidden = gifts.length === 0;
    els.noGifts.hidden = gifts.length !== 0;

    renderOtherMethod(a, currency);
    renderProfit(a, currency);

    els.analyticsEmpty.hidden = true;
    els.analyticsBody.hidden = false;
    hasAnalytics = true;
}

function setAnalyticsLoading(on) {
    els.analyticsLoading.hidden = !on;
    els.tiles.classList.toggle('is-stale', on && hasAnalytics);
    els.analyticsBody.setAttribute('aria-busy', on ? 'true' : 'false');
}

async function loadAnalytics() {
    const seq = ++analyticsSeq;
    const range = String(els.range.value || els.range.dataset.default);
    clearBanner(els.analyticsBanner);
    setAnalyticsLoading(true);
    try {
        const data = await api('GET', `/api/analytics?range=${encodeURIComponent(range)}`);
        if (seq !== analyticsSeq) return;
        renderAnalytics(data);
    } catch (e) {
        if (seq !== analyticsSeq) return;
        els.analyticsBody.hidden = true;
        els.analyticsEmpty.hidden = true;
        hasAnalytics = false;
        if (e.status === 403 && e.message === 'needs_read_orders') {
            els.analyticsScope.hidden = false;
            showBanner(els.analyticsBanner, 'info', t('analytics.needs_orders'));
        } else {
            els.analyticsScope.hidden = true;
            showBanner(els.analyticsBanner, 'critical', e.message);
        }
    } finally {
        if (seq === analyticsSeq) setAnalyticsLoading(false);
    }
}

async function grantOrders() {
    if (await requestScope('read_orders', els.analyticsBanner)) loadAnalytics();
}

async function grantInventory() {
    if (await requestScope('read_inventory', els.analyticsBanner)) loadAnalytics();
}

// ---------------------------------------------------------------- Test page

const testLines = []; // { variantId: ?string, title: string, price: ?string, quantity: number }
const PRICE_RE = /^\d+(\.\d+)?$/;
const MAX_TEST_LINES = 50;

function renderTestSaved() {
    const settings = loaded?.settings;
    els.testSaved.textContent = settings && Number.isInteger(settings.percent)
        ? t('test.saved', { percent: settings.percent, method: t(`settings.method_${settings.method === 'company' ? 'company' : 'customer'}`) })
        : '';
}

function renderTestLines() {
    els.testEmpty.hidden = testLines.length > 0;
    els.testLines.hidden = testLines.length === 0;
    els.testRun.toggleAttribute('disabled', testLines.length === 0);
    els.testLines.replaceChildren(
        ...testLines.map((line, index) => {
            const li = document.createElement('li');
            li.className = 'test-line';

            const title = document.createElement('div');
            title.className = 'test-line-title';
            title.textContent = line.price === null ? line.title : `${line.title} (${line.price})`;

            const qty = document.createElement('s-number-field');
            qty.setAttribute('label', t('test.quantity'));
            qty.setAttribute('min', '1');
            qty.setAttribute('max', '200');
            qty.setAttribute('step', '1');
            qty.setAttribute('value', String(line.quantity));
            const onQty = () => {
                const n = Math.floor(Number(qty.value));
                line.quantity = Number.isFinite(n) && n >= 1 ? Math.min(n, 200) : 1;
            };
            qty.addEventListener('input', onQty);
            qty.addEventListener('change', onQty);

            const remove = document.createElement('s-button');
            remove.setAttribute('variant', 'tertiary');
            remove.textContent = t('test.remove');
            remove.addEventListener('click', () => {
                testLines.splice(index, 1);
                renderTestLines();
            });

            li.append(title, qty, remove);
            return li;
        }),
    );
}

async function pickVariants() {
    try {
        const picked = await shopify.resourcePicker({ type: 'variant', multiple: true });
        if (!Array.isArray(picked)) return; // cancelled
        for (const v of picked) {
            if (!v || typeof v.id !== 'string' || testLines.length >= MAX_TEST_LINES) continue;
            if (testLines.some((l) => l.variantId === v.id)) continue;
            const name = typeof v.displayName === 'string' && v.displayName !== '' ? v.displayName : `${v.product?.title ?? ''} ${v.title ?? ''}`.trim();
            testLines.push({ variantId: v.id, title: name || v.id, price: null, quantity: 1 });
        }
        renderTestLines();
    } catch {
        // the picker was closed or could not open; nothing to change
    }
}

/** Arabic-Indic and Persian digits to ASCII, so a typed price like ١٩٫٩٠ is accepted. */
function normalizeDigits(text) {
    return text
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06F0));
}

function addTypedLine() {
    const title = String(els.typedTitle.value ?? '').trim();
    const price = normalizeDigits(String(els.typedPrice.value ?? '').trim()).replace(/[,٫]/, '.');
    if (title === '' || title.length > 255 || !PRICE_RE.test(price) || price.length > 12 || testLines.length >= MAX_TEST_LINES) {
        els.typedPrice.setAttribute('error', t('test.item_error'));
        return;
    }
    els.typedPrice.removeAttribute('error');
    testLines.push({ variantId: null, title, price, quantity: 1 });
    els.typedTitle.value = '';
    els.typedPrice.value = '';
    renderTestLines();
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function roleText(role, percent) {
    if (role === 'gift') return t('test.role_gift');
    if (role === 'single') return t('test.role_single', { pct: percent });
    return t('test.role_paid');
}

function unitsTable(units, currency, percent) {
    const table = el('table', 'grid-table');
    const head = el('tr');
    for (const [key, numeric] of [['piece', false], ['role', false], ['price_col', true], ['discount_col', true], ['pays_col', true]]) {
        head.append(el('th', numeric ? 'num' : '', t(`test.${key}`)));
    }
    const thead = el('thead');
    thead.append(head);
    const tbody = el('tbody');
    for (const u of units) {
        const row = el('tr');
        row.append(
            el('td', '', String(u.title ?? '')),
            el('td', '', roleText(u.role, percent)),
            el('td', 'num', formatMoney(u.price, currency)),
            el('td', 'num', formatMoney(u.discount, currency)),
            el('td', 'num', formatMoney(u.pays, currency)),
        );
        tbody.append(row);
    }
    table.append(thead, tbody);
    return table;
}

function splitsList(candidates, currency, percent) {
    const list = el('ul', 'splits');
    for (const c of candidates) {
        const li = el('li', `split${c.chosen ? ' is-chosen' : ''}`);
        const single = c.single === null ? t('test.cand_no_single') : t('test.cand_single', { pct: percent, title: c.single });
        const gifts = Array.isArray(c.gifts) && c.gifts.length > 0 ? t('test.cand_gifts', { titles: c.gifts.join(', ') }) : t('test.cand_none');
        const money = `${t('test.discount', { amount: formatMoney(c.totalDiscount, currency) })} · ${t('test.shopper_pays', { amount: formatMoney(c.totalPay, currency) })}`;
        li.append(el('div', '', `${single}. ${gifts}`), el('div', 'num', money));
        if (c.chosen) {
            li.prepend(el('strong', '', `${t('test.chosen')} `));
        }
        list.append(li);
    }
    return list;
}

function methodCard(method, res, data) {
    const card = el('section', 'method-card');
    const heading = el('h3', '', t(`test.${method}`));
    if (data.savedMethod === method) {
        heading.append(' ', el('span', 'badge', t('test.saved_badge')));
    }
    const currency = data.currency;
    card.append(
        heading,
        el('div', '', t('test.shopper_pays', { amount: formatMoney(res.totalPay, currency) })),
        el('div', '', t('test.discount', { amount: formatMoney(res.totalDiscount, currency) })),
        el('h3', '', t('test.units')),
        unitsTable(Array.isArray(res.units) ? res.units : [], currency, data.percent),
        el('h3', '', t('test.candidates')),
        splitsList(Array.isArray(res.candidates) ? res.candidates : [], currency, data.percent),
    );
    if (res.candidatesCapped) {
        card.append(el('div', 'note-subdued', t('test.capped', { n: formatCount(res.candidates.length), total: formatCount(res.candidatesTotal) })));
    }
    return card;
}

function renderTestResult(data) {
    const cards = el('div', 'methods');
    cards.append(methodCard('customer', data.customer, data), methodCard('company', data.company, data));
    const parts = [cards];

    const ineligible = Array.isArray(data.ineligible) ? data.ineligible : [];
    if (ineligible.length > 0) {
        const box = el('div', 'stack-tight');
        box.append(el('h3', 'table-title', t('test.ineligible')));
        const list = el('ul', 'splits');
        for (const item of ineligible) {
            const reason = t(`test.reason.${item.reason}`);
            list.append(el('li', '', `${item.title}: ${reason === `test.reason.${item.reason}` ? item.reason : reason}`));
        }
        box.append(list);
        parts.push(box);
    }
    els.testResult.replaceChildren(...parts);
    els.testResult.hidden = false;
}

let testing = false;

async function runTest() {
    if (testing || testLines.length === 0) return;
    testing = true;
    clearBanner(els.testBanner);
    els.testRun.setAttribute('loading', '');
    els.testRun.setAttribute('disabled', '');
    try {
        const data = await api('POST', '/api/test-cart', {
            lines: testLines.map((l) => (l.variantId !== null
                ? { variantId: l.variantId, quantity: l.quantity }
                : { title: l.title, price: l.price, quantity: l.quantity })),
        });
        renderTestResult(data);
        // Only a run that priced at least one piece counts as having tried the offer.
        const priced = [data.customer, data.company].some((r) => Array.isArray(r?.units) && r.units.length > 0);
        if (priced) {
            const marks = readStore(MARKS_KEY);
            marks.test = true;
            writeStore(MARKS_KEY, marks);
            renderChecklist();
        }
        showBanner(els.testBanner, priced ? 'success' : 'info', priced ? t('test.done') : t('test.none_priced'));
    } catch (e) {
        els.testResult.hidden = true;
        showBanner(els.testBanner, e.status === 403 && e.message === 'needs_read_products' ? 'warning' : 'critical',
            e.status === 403 && e.message === 'needs_read_products' ? t('test.needs_products') : e.message);
    } finally {
        testing = false;
        els.testRun.removeAttribute('loading');
        els.testRun.toggleAttribute('disabled', testLines.length === 0);
    }
}

// ---------------------------------------------------------------- Wiring

els.range.value = els.range.dataset.default;
els.checklistProgress.textContent = t('common.loading');

els.enabled.addEventListener('change', setSwitchLabel);
els.enabled.addEventListener('input', setSwitchLabel);
els.percent.addEventListener('input', () => els.percent.removeAttribute('error'));
els.method.addEventListener('change', renderMethodHelp);
els.appliesTo.addEventListener('change', renderAppliesTo);
els.tag.addEventListener('input', () => {
    els.tag.removeAttribute('error');
    renderTagNote();
});
els.tag.addEventListener('change', renderTagNote);
els.collectionPick.addEventListener('click', pickCollection);
els.clearEnd.addEventListener('click', () => {
    els.endsAt.value = '';
    setError(els.datesError, null);
    renderEndNote();
});
els.startsAt.addEventListener('input', () => setError(els.datesError, null));
els.endsAt.addEventListener('input', () => {
    setError(els.datesError, null);
    renderEndNote();
});
els.enabled.addEventListener('change', renderEndNote);
els.save.addEventListener('click', save);
els.form.addEventListener('submit', (e) => {
    e.preventDefault();
    save();
});
els.range.addEventListener('change', loadAnalytics);
els.ordersGrant.addEventListener('click', grantOrders);
els.inventoryGrant.addEventListener('click', grantInventory);
els.testPick.addEventListener('click', pickVariants);
els.typedAdd.addEventListener('click', addTypedLine);
els.typedPrice.addEventListener('input', () => els.typedPrice.removeAttribute('error'));
els.testRun.addEventListener('click', runTest);
renderTestLines();

showPage(pageFromPath(location.pathname));
loadSettings();
loadBilling();
