// Le Voile BOGO admin page. Every /api call carries a fresh App Bridge session token;
// nothing secret is ever read from or written to the page.

const GENERIC_ERROR = 'حصلت مشكلة في الاتصال بالسيرفر، جرّب تاني';

const $ = (sel) => document.querySelector(sel);

const els = {
    settingsBanner: $('#settings-banner'),
    settingsLoading: $('#settings-loading'),
    form: $('#settings-form'),
    enabled: $('#enabled'),
    percent: $('#percent'),
    save: $('#save'),
    status: $('#discount-status'),
    range: $('#range'),
    analyticsBanner: $('#analytics-banner'),
    analyticsLoading: $('#analytics-loading'),
    analyticsEmpty: $('#analytics-empty'),
    analyticsBody: $('#analytics-body'),
    tiles: $('#tiles'),
    percentTileLabel: $('#percent-tile-label'),
    capped: $('#capped-note'),
    topGifts: $('#top-gifts'),
    topGiftsRows: $('#top-gifts-rows'),
};

const intFmt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
const pctFmt = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
const moneyFmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const formatCount = (n) => intFmt.format(Number(n) || 0);
const formatPct = (n) => pctFmt.format(Number(n) || 0);
const formatMoney = (n) => `${moneyFmt.format(Number(n) || 0)} ج.م`;

// ---------------------------------------------------------------- API

class ApiError extends Error {}

async function api(method, path, body) {
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
        throw new ApiError(GENERIC_ERROR);
    }

    let data = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }

    if (res.status !== 200) {
        const serverError = data && typeof data.error === 'string' && data.error !== '' ? data.error : null;
        // 422 carries Shopify's / our error string, shown verbatim; anything else without one is generic.
        throw new ApiError(serverError ?? GENERIC_ERROR);
    }

    return data ?? {};
}

// ---------------------------------------------------------------- UI helpers

function showBanner(slot, tone, text) {
    const banner = document.createElement('s-banner');
    banner.setAttribute('tone', tone);
    if (tone === 'success') {
        banner.setAttribute('dismissible', '');
        banner.addEventListener('afterhide', () => banner.remove());
    }
    banner.textContent = text;
    slot.replaceChildren(banner);
}

const clearBanner = (slot) => slot.replaceChildren();

function setSwitchLabel() {
    const on = Boolean(els.enabled.checked);
    els.enabled.setAttribute('label', on ? els.enabled.dataset.labelOn : els.enabled.dataset.labelOff);
}

function percentLabel(pct) {
    return els.tiles.dataset.percentTemplate.replace('{pct}', String(pct));
}

// ---------------------------------------------------------------- Settings

function renderStatus(discount) {
    let state = 'missing';
    if (discount) {
        state = discount.status === 'ACTIVE' ? 'active' : 'inactive';
    }
    for (const row of els.status.querySelectorAll('[data-status]')) {
        row.hidden = row.dataset.status !== state;
    }
    if (discount) {
        for (const link of els.status.querySelectorAll('[data-discount-link]')) {
            link.setAttribute('href', discount.adminUrl);
        }
    }
}

function renderSettings(data) {
    const settings = data.settings ?? {};
    els.enabled.checked = Boolean(settings.enabled);
    setSwitchLabel();
    if (Number.isInteger(settings.percent)) {
        els.percent.value = String(settings.percent);
        els.percentTileLabel.textContent = percentLabel(settings.percent);
    }
    renderStatus(data.discount ?? null);
}

async function loadSettings() {
    try {
        const data = await api('GET', '/api/settings');
        renderSettings(data);
        els.form.hidden = false;
    } catch (e) {
        showBanner(els.settingsBanner, 'critical', e.message);
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
        els.percent.setAttribute('error', els.percent.dataset.rangeError);
        return null;
    }
    els.percent.removeAttribute('error');
    return n;
}

let saving = false;

async function save() {
    if (saving) return;
    const percent = readPercent();
    if (percent === null) return;

    saving = true;
    clearBanner(els.settingsBanner);
    els.save.setAttribute('loading', '');
    els.save.setAttribute('disabled', '');
    try {
        const data = await api('PUT', '/api/settings', { enabled: Boolean(els.enabled.checked), percent });
        renderSettings(data);
        if (typeof data.message === 'string' && data.message !== '') {
            showBanner(els.settingsBanner, 'success', data.message);
        }
    } catch (e) {
        showBanner(els.settingsBanner, 'critical', e.message);
    } finally {
        saving = false;
        els.save.removeAttribute('loading');
        els.save.removeAttribute('disabled');
    }
}

// ---------------------------------------------------------------- Analytics

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

    setField('offerOrders', formatCount(a.offerOrders));
    setField('offerOrdersPct', formatPct(a.offerOrdersPct));
    setField('giftUnits', formatCount(a.giftUnits));
    setField('percentUnits', formatCount(a.percentUnits));
    setField('totalDiscount', formatMoney(a.totalDiscount));
    setField('scenario2Extra', formatMoney(a.scenario2Extra));
    els.capped.hidden = !a.capped;

    const gifts = Array.isArray(a.topGifts) ? a.topGifts : [];
    els.topGiftsRows.replaceChildren(
        ...gifts.map((g) => {
            const row = document.createElement('s-table-row');
            row.append(cell(String(g.title ?? '')), cell(formatCount(g.units), true), cell(formatMoney(g.value), true));
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
        showBanner(els.analyticsBanner, 'critical', e.message);
    } finally {
        if (seq === analyticsSeq) setAnalyticsLoading(false);
    }
}

// ---------------------------------------------------------------- Wiring

// Start on the default range even if the select has not applied its value attribute yet.
els.range.value = els.range.dataset.default;

els.enabled.addEventListener('change', setSwitchLabel);
els.enabled.addEventListener('input', setSwitchLabel);
els.percent.addEventListener('input', () => els.percent.removeAttribute('error'));
els.save.addEventListener('click', save);
els.form.addEventListener('submit', (e) => {
    e.preventDefault();
    save();
});
els.range.addEventListener('change', loadAnalytics);

loadSettings();
loadAnalytics();
