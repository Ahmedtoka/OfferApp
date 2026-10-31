import test from 'node:test';
import assert from 'node:assert/strict';
import { summarySentence } from '../../public/js/summary.js';

// Stand-in for the app's t(): templates with {name} placeholders, like lang/en/app.php.
const strings = {
    'analytics.summary.with_aov': 'Offer orders average {aov}% {dir} than other orders, and the discount was {discount}% of value before discount.',
    'analytics.summary.same_aov': 'Offer orders average about the same as other orders, and the discount was {discount}% of value before discount.',
    'analytics.summary.without_aov': 'The discount was {discount}% of value before discount.',
    'analytics.summary.more': 'more',
    'analytics.summary.less': 'less',
    'analytics.summary.won': 'On products with a known cost ({covered}% of units) the offer won.',
};
const t = (key, vars = {}) => Object.entries(vars).reduce((s, [k, v]) => s.split(`{${k}}`).join(String(v)), strings[key] ?? key);

const base = { aovOffer: 168, aovOther: 100, discountPctOfSales: 22, profit: null };

test('with AOV data the sentence includes both percentages', () => {
    const s = summarySentence(base, t);
    assert.match(s, /68%/);
    assert.match(s, /22%/);
    assert.match(s, /more/);
});

test('a lower AOV says less', () => {
    assert.match(summarySentence({ ...base, aovOffer: 80 }, t), /20% less/);
});

test('"won" appears only when profit is present and positive', () => {
    assert.doesNotMatch(summarySentence(base, t), /won/);
    assert.doesNotMatch(summarySentence({ ...base, profit: { amount: -5, coveredPct: 40 } }, t), /won/);
    assert.doesNotMatch(summarySentence({ ...base, profit: { amount: 0, coveredPct: 40 } }, t), /won/);
    assert.match(summarySentence({ ...base, profit: { amount: 120, coveredPct: 40 } }, t), /won/);
});

test('no comparison clause when aovOther is null (or zero)', () => {
    for (const aovOther of [null, 0]) {
        const s = summarySentence({ ...base, aovOther }, t);
        assert.doesNotMatch(s, /average/);
        assert.match(s, /22%/);
    }
    assert.doesNotMatch(summarySentence({ ...base, aovOffer: null }, t), /average/);
});

test('a rounded difference of zero says about the same', () => {
    const s = summarySentence({ ...base, aovOffer: 100.02 }, t);
    assert.match(s, /about the same/);
    assert.doesNotMatch(s, /more|less/);
});

test('numbers go through the supplied formatter', () => {
    const s = summarySentence(base, t, (n) => `<${n}>`);
    assert.match(s, /<68>%/);
    assert.match(s, /<22>%/);
});
