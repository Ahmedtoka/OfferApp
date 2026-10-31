// Pairly admin page. Every /api call carries a fresh App Bridge session token; nothing secret is ever
// read from or written to the page. All user-facing text comes from the server (the #i18n JSON block).

import { toIso, fromIso, stateKey, checklist, themeEditorUrl } from './js/lib.js';

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
const COLLECTIONS_KEY = 'pairly.collections';

// ---------------------------------------------------------------- elements

const els = {
    billingBanner: $('#billing-banner'),
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
    coupons: $('#coupons'),
    save: $('#save'),
    range: $('#range'),
    analyticsBanner: $('#analytics-banner'),
    analyticsLoading: $('#analytics-loading'),
    analyticsEmpty: $('#analytics-empty'),
    analyticsBody: $('#analytics-body'),
    tiles: $('#tiles'),
    offerOrdersPct: $('#offer-orders-pct'),
    percentTileLabel: $('#percent-tile-label'),
    capped: $('#capped-note'),
    topGifts: $('#top-gifts'),
    topGiftsRows: $('#top-gifts-rows'),
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

function renderCollection() {
    if (collectionId === null) {
        els.collectionTitle.textContent = t('settings.collection_none');
        els.collectionPick.textContent = t('settings.collection_choose');
        return;
    }
    const title = readStore(COLLECTIONS_KEY)[collectionId];
    els.collectionTitle.textContent = typeof title === 'string' && title !== ''
        ? title
        : t('settings.collection_unknown', { id: collectionId.split('/').pop() });
    els.collectionPick.textContent = t('settings.collection_change');
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
        const titles = readStore(COLLECTIONS_KEY);
        titles[item.id] = String(item.title ?? '');
        writeStore(COLLECTIONS_KEY, titles);
        setError(els.collectionError, null);
        renderCollection();
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

function renderBillingEnded(settings) {
    if (settings.disabledReason === 'billing') {
        showBanner(els.billingBanner, 'warning', t('billing.ended'), { label: t('billing.subscribe'), onClick: subscribe });
    }
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
    renderCollection();
    els.startsAt.value = fromIso(settings.startsAt);
    els.endsAt.value = fromIso(settings.endsAt);
    setError(els.datesError, null);
    els.coupons.checked = Boolean(settings.combineWithCoupons);
    renderState(data);
    renderChecklist();
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
        }
    } catch (e) {
        showBanner(els.billingBanner, 'critical', e.message);
    }
}

let saving = false;

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
    try {
        const data = await api('PUT', '/api/settings', {
            enabled: Boolean(els.enabled.checked),
            percent,
            method: String(els.method.value || els.method.dataset.default),
            appliesTo: tagMode ? 'tag' : 'collection',
            eligibleTag: tagMode ? eligibleTag : null,
            collectionId: tagMode ? null : collectionId,
            combineWithCoupons: Boolean(els.coupons.checked),
            startsAt: dates.startsAt,
            endsAt: dates.endsAt,
        });
        renderSettings(data);
        showBanner(els.settingsBanner, 'success', typeof data.message === 'string' && data.message !== '' ? data.message : t('settings.saved_fallback'));
    } catch (e) {
        if (e.status === 402) {
            showBanner(els.billingBanner, 'warning', e.message, { label: t('billing.subscribe'), onClick: subscribe });
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
    try {
        if (typeof shopify.config?.shop === 'string') return shopify.config.shop;
    } catch {
        // fall through to the query string
    }
    return new URLSearchParams(location.search).get('shop') ?? '';
}

function stepAction(id) {
    if (id === 'test') return () => goTo('test');
    if (id === 'theme') {
        return () => open(themeEditorUrl(shopDomain(), document.body.dataset.apiKey, document.body.dataset.themeHandle), '_blank');
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

// ---------------------------------------------------------------- Analytics (Task 12 rebuilds this section)

let analyticsSeq = 0;
let hasAnalytics = false;

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

function renderAnalytics(a) {
    if ((Number(a.ordersTotal) || 0) === 0) {
        els.analyticsBody.hidden = true;
        els.analyticsEmpty.hidden = false;
        return;
    }

    const currency = typeof a.currency === 'string' ? a.currency : '';
    setField('offerOrders', formatCount(a.offerOrders));
    els.offerOrdersPct.textContent = t('analytics.of_all_orders', { pct: formatPct(a.offerOrdersPct) });
    setField('giftUnits', formatCount(a.giftUnits));
    setField('percentUnits', formatCount(a.percentUnits));
    setField('totalDiscount', formatMoney(a.totalDiscount, currency));
    if (loaded && Number.isInteger(loaded.settings?.percent)) {
        els.percentTileLabel.textContent = t('analytics.percent_pieces', { pct: loaded.settings.percent });
    }
    els.capped.textContent = t('analytics.capped', { n: formatCount(els.capped.dataset.count) });
    els.capped.hidden = !a.capped;

    const gifts = Array.isArray(a.topGifts) ? a.topGifts : [];
    els.topGiftsRows.replaceChildren(
        ...gifts.map((g) => {
            const row = document.createElement('s-table-row');
            row.append(cell(String(g.title ?? '')), cell(formatCount(g.units), true), cell(formatMoney(g.value, currency), true));
            return row;
        }),
    );
    els.topGifts.hidden = gifts.length === 0;

    els.analyticsEmpty.hidden = true;
    els.analyticsBody.hidden = false;
    hasAnalytics = true;
}

function setAnalyticsLoading(on) {
    els.analyticsLoading.hidden = !on;
    els.tiles.classList.toggle('is-stale', on && hasAnalytics);
    els.topGifts.classList.toggle('is-stale', on && hasAnalytics);
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
        showBanner(els.analyticsBanner, 'critical', e.message === 'needs_read_orders' ? t('analytics.needs_orders') : e.message);
    } finally {
        if (seq === analyticsSeq) setAnalyticsLoading(false);
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
});
els.startsAt.addEventListener('input', () => setError(els.datesError, null));
els.endsAt.addEventListener('input', () => setError(els.datesError, null));
els.save.addEventListener('click', save);
els.form.addEventListener('submit', (e) => {
    e.preventDefault();
    save();
});
els.range.addEventListener('change', loadAnalytics);

showPage(pageFromPath(location.pathname));
loadSettings();
