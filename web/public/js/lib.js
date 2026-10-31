// Pure helpers for the admin page (no DOM, no user-facing text). Unit-tested with `node --test web/tests/js/*.test.mjs`.

const pad = (n) => String(n).padStart(2, '0');

/** A <input type="datetime-local"> value (merchant's local time) to ISO 8601 with a timezone, or null. */
export function toIso(local) {
    if (typeof local !== 'string' || local === '') return null;
    const d = new Date(local);
    return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

/** An ISO 8601 instant to a datetime-local value in the merchant's local time, or ''. */
export function fromIso(iso) {
    if (typeof iso !== 'string' || iso === '') return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function stateKey(state) {
    return ['off', 'scheduled', 'running', 'ended'].includes(state) ? state : 'off';
}

/**
 * Onboarding steps. Done is computed from saved state where it can be read; the test page and the theme embed
 * cannot be read from here, so they use the merchant's own marks.
 *
 * @param {{settings?: object, state?: string}} data  the /api/settings payload
 * @param {{test?: boolean, theme?: boolean}} marks
 */
export function checklist(data, marks) {
    const s = data.settings ?? {};
    const hasProducts = s.appliesTo === 'collection' ? Boolean(s.collectionId) : String(s.eligibleTag ?? '').trim() !== '';
    const saved = Boolean(s.updated_at);
    return [
        { id: 'products', done: hasProducts },
        { id: 'percent', done: hasProducts && saved },
        { id: 'test', done: Boolean(marks.test) },
        { id: 'theme', done: Boolean(marks.theme) },
        { id: 'on', done: Boolean(s.enabled) && stateKey(data.state) !== 'off' },
    ];
}

/** Deep link to the theme editor with the app embed switched on (shopify.dev: theme app extension configuration). */
export function themeEditorUrl(shop, apiKey, handle) {
    return `https://${shop}/admin/themes/current/editor?context=apps&activateAppId=${encodeURIComponent(apiKey)}/${encodeURIComponent(handle)}`;
}

/** Shopify's `locale` param (e.g. ar, ar-EG): Arabic is RTL, everything else English. */
export function localeInfo(locale) {
    const base = String(locale ?? '').toLowerCase().split(/[-_]/)[0];
    return base === 'ar' ? { lang: 'ar', dir: 'rtl' } : { lang: 'en', dir: 'ltr' };
}
