import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import { cartLinesDiscountsGenerateRun } from '../src/cart_lines_discounts_generate_run.js';
import { toAmountString, toPiastres } from '../src/money.js';

const cases = JSON.parse(fs.readFileSync(new URL('../../../shared/fixtures/bogo-cases.json', import.meta.url), 'utf8'));
const byName = (n) => cases.find((c) => c.name === n);
const constants = JSON.parse(fs.readFileSync(new URL('../../../shared/offer-constants.json', import.meta.url), 'utf8'));
const GIFT = constants.giftMessage;
const pctMsg = (pct) => constants.percentMessageTemplate.replace('{pct}', String(pct));

const money = (v) => ({ amount: toAmountString(toPiastres(v) ).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '.0') });

function inputFor(c, { enabled = true, percent = 30, classes = ['PRODUCT'], metafield, giftCardIds = [], untaggedIds = [] } = {}) {
  return {
    cart: {
      lines: c.lines.map((l) => ({
        id: `gid://shopify/CartLine/${l.id}`,
        quantity: l.quantity,
        cost: {
          amountPerQuantity: money(l.pNow),
          compareAtAmountPerQuantity: l.pFull > l.pNow ? money(l.pFull) : null,
        },
        merchandise: {
          __typename: 'ProductVariant',
          id: `gid://shopify/ProductVariant/${l.id}`,
          product: {
            id: `gid://shopify/Product/${l.id}`,
            isGiftCard: giftCardIds.includes(l.id),
            hasAnyTag: !untaggedIds.includes(l.id),
          },
        },
      })),
    },
    discount: { discountClasses: classes },
    shop: { metafield: metafield === undefined ? { value: JSON.stringify({ enabled, percent }) } : metafield },
  };
}

const run = (input) => cartLinesDiscountsGenerateRun(input);
const candidates = (out) => out.operations[0].productDiscountsAdd.candidates;
const line = (id) => `gid://shopify/CartLine/${id}`;
const cand = (id, quantity, message, amount) => ({
  message,
  targets: [{ cartLine: { id: line(id), quantity } }],
  value: { fixedAmount: { amount, appliesToEachItem: false } },
});

describe('fixture cases with a per-line plan', () => {
  it.each(cases.filter((c) => c.perLine))('$name', (c) => {
    const expected = [];
    for (const l of c.lines) {
      const p = c.perLine[l.id];
      if (!p) continue;
      if (p.giftQty) expected.push(cand(l.id, p.giftQty, GIFT, toAmountString(toPiastres(p.giftAmount))));
      if (p.singleQty) expected.push(cand(l.id, p.singleQty, pctMsg(30), toAmountString(toPiastres(p.singleAmount))));
    }
    const out = run(inputFor(c));
    expect(out.operations).toHaveLength(1);
    expect(out.operations[0].productDiscountsAdd.selectionStrategy).toBe('ALL');
    expect(candidates(out)).toEqual(expected);
  });
});

describe('specific shapes', () => {
  it('abc: gift on B, single on C', () => {
    expect(candidates(run(inputFor(byName('abc'))))).toEqual([
      cand('B', 1, GIFT, '1100.00'),
      cand('C', 1, pctMsg(30), '270.00'),
    ]);
  });
  it('mixed_2_and_3: Y gift + single, X gift, in input order', () => {
    expect(candidates(run(inputFor(byName('mixed_2_and_3'))))).toEqual([
      cand('X', 1, GIFT, '1200.00'),
      cand('Y', 1, GIFT, '900.00'),
      cand('Y', 1, pctMsg(30), '270.00'),
    ]);
  });
  it('percent 40 changes message and amount', () => {
    expect(candidates(run(inputFor(byName('a'), { percent: 40 })))).toEqual([cand('A', 1, pctMsg(40), '480.00')]);
  });
  it('excludes gift cards', () => {
    const c = byName('ac');
    expect(run(inputFor(c, { giftCardIds: ['C'] })).operations).toEqual([
      { productDiscountsAdd: { selectionStrategy: 'ALL', candidates: [cand('A', 1, pctMsg(30), '360.00')] } },
    ]);
  });
  it('markdown 650/1000 alone yields no zero-amount candidate', () => {
    expect(run(inputFor(byName('markdown_single_650')))).toEqual({ operations: [] });
  });
});

describe('eligibility: only products tagged with the eligible tag', () => {
  const abc = byName('abc'); // A 1200, B 1100, C 900
  const ab = { lines: abc.lines.filter((l) => l.id !== 'C') };
  it('untagged line is ignored: A alone gets the single, B untouched', () => {
    expect(candidates(run(inputFor(ab, { untaggedIds: ['B'] })))).toEqual([cand('A', 1, pctMsg(30), '360.00')]);
  });
  it('all lines untagged: no operations', () => {
    expect(run(inputFor(abc, { untaggedIds: ['A', 'B', 'C'] }))).toEqual({ operations: [] });
  });
  it('A, B tagged + C untagged: A+B pair, B gift, no single', () => {
    expect(candidates(run(inputFor(abc, { untaggedIds: ['C'] })))).toEqual([cand('B', 1, GIFT, '1100.00')]);
  });
  it('untagged line with quantity is never expanded, gifted or paired', () => {
    const c = { lines: [{ id: 'A', quantity: 1, pNow: 1200, pFull: 1200 }, { id: 'B', quantity: 3, pNow: 2000, pFull: 2000 }] };
    expect(candidates(run(inputFor(c, { untaggedIds: ['B'] })))).toEqual([cand('A', 1, pctMsg(30), '360.00')]);
  });
  it('hasAnyTag missing or not strictly true is not eligible', () => {
    const input = inputFor(ab);
    delete input.cart.lines[1].merchandise.product.hasAnyTag;
    expect(candidates(run(input))).toEqual([cand('A', 1, pctMsg(30), '360.00')]);
    input.cart.lines[1].merchandise.product.hasAnyTag = 'true';
    expect(candidates(run(input))).toEqual([cand('A', 1, pctMsg(30), '360.00')]);
  });
  it('isGiftCard must be strictly false', () => {
    const input = inputFor(ab);
    delete input.cart.lines[1].merchandise.product.isGiftCard;
    expect(candidates(run(input))).toEqual([cand('A', 1, pctMsg(30), '360.00')]);
  });
});

describe('per-currency rounding', () => {
  const withCurrency = (lines, currencyCode) => ({
    cart: { cost: { subtotalAmount: { currencyCode } }, lines },
    discount: { discountClasses: ['PRODUCT'] },
    shop: { metafield: { value: JSON.stringify({ enabled: true, percent: 30 }) } },
  });
  const mkLine = (id, quantity, amount) => ({
    id: line(id),
    quantity,
    cost: { amountPerQuantity: { amount }, compareAtAmountPerQuantity: null },
    merchandise: {
      __typename: 'ProductVariant',
      id: `gid://shopify/ProductVariant/${id}`,
      product: { id: `gid://shopify/Product/${id}`, isGiftCard: false, hasAnyTag: true },
    },
  });

  it('KWD single line 1.235 at 30% gives a valid three-decimal amount', () => {
    const out = run(withCurrency([mkLine('A', 1, '1.235')], 'KWD'));
    expect(candidates(out)).toEqual([cand('A', 1, pctMsg(30), '0.370')]);
  });

  it('JPY gift equals the whole line price with no decimals', () => {
    const out = run(withCurrency([mkLine('A', 2, '1000.0')], 'JPY'));
    expect(candidates(out)).toEqual([cand('A', 1, GIFT, '1000')]);
  });

  it('JPY single is a whole number', () => {
    const out = run(withCurrency([mkLine('A', 1, '999')], 'JPY'));
    expect(candidates(out)).toEqual([cand('A', 1, pctMsg(30), '300')]);
  });

  it('missing currency falls back to two decimals', () => {
    const out = run(inputFor(byName('a')));
    expect(candidates(out)).toEqual([cand('A', 1, pctMsg(30), '360.00')]);
  });
});

describe('gating returns no operations', () => {
  const a = byName('a');
  it.each([
    ['disabled', inputFor(a, { enabled: false })],
    ['no metafield', inputFor(a, { metafield: null })],
    ['percent 0', inputFor(a, { percent: 0 })],
    ['percent 100', inputFor(a, { percent: 100 })],
    ['percent 30.5', inputFor(a, { percent: 30.5 })],
    ['not json', inputFor(a, { metafield: { value: 'not json' } })],
    ['enabled missing', inputFor(a, { metafield: { value: '{"percent":30}' } })],
    // Security review: malformed or wrongly-typed config always fails closed (no discount, no throw).
    ['json null', inputFor(a, { metafield: { value: 'null' } })],
    ['json array', inputFor(a, { metafield: { value: '[]' } })],
    ['json scalar', inputFor(a, { metafield: { value: '123' } })],
    ['truncated json', inputFor(a, { metafield: { value: '{' } })],
    ['empty value', inputFor(a, { metafield: { value: '' } })],
    ['enabled as string', inputFor(a, { metafield: { value: '{"enabled":"true","percent":30}' } })],
    ['percent as string', inputFor(a, { metafield: { value: '{"enabled":true,"percent":"30"}' } })],
    ['percent overflow', inputFor(a, { metafield: { value: '{"enabled":true,"percent":1e400}' } })],
    ['shop null', { ...inputFor(a), shop: null }],
    ['ORDER class only', inputFor(a, { classes: ['ORDER'] })],
    ['empty cart', inputFor({ lines: [] })],
  ])('%s', (_n, input) => {
    expect(run(input)).toEqual({ operations: [] });
  });
});
