import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import { expandUnits, sortUnits, singleDiscount, computeOffer, planLineDiscounts } from '../src/bogo.js';
import { toPiastres, toAmountString, toMinor, decimalsFor } from '../src/money.js';

const cases = JSON.parse(fs.readFileSync(new URL('../../../shared/fixtures/bogo-cases.json', import.meta.url), 'utf8'));
const P = (lines) => lines.map(l => ({ ...l, pNow: toPiastres(l.pNow), pFull: toPiastres(l.pFull) }));

describe.each(cases)('$name', (c) => {
  it('pays the expected total and saving', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'customer');
    expect(r.totalPay).toBe(toPiastres(c.expectedPay));
    expect(r.totalDiscount).toBe(toPiastres(c.expectedSaving));
  });
  it('gifts and single land on the expected units', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'customer');
    expect(r.singleUnit?.lineId ?? null).toBe(c.expectedSingle ?? null);
    if (c.expectedGifts === undefined) return;
    const gifts = {};
    r.assignments.filter(a => a.role === 'gift').forEach(a => { gifts[a.unit.lineId] = (gifts[a.unit.lineId] ?? 0) + 1; });
    expect(gifts).toEqual(c.expectedGifts);
  });
  it('per-line plan matches', () => {
    const plan = Object.fromEntries(planLineDiscounts(P(c.lines), c.pct).map(p => [p.lineId, p]));
    for (const [id, exp] of Object.entries(c.perLine ?? {})) {
      expect(plan[id]).toMatchObject({ giftQty: exp.giftQty, giftAmount: toPiastres(exp.giftAmount), singleQty: exp.singleQty, singleAmount: toPiastres(exp.singleAmount) });
    }
  });
});

// §4.4 prices in piastres: A 1200, B 1100, C 900, D 800 (no markdown).
const PRICE = { A: 1200, B: 1100, C: 900, D: 800 };
const pay = (ids, pct, method) =>
  computeOffer(expandUnits(ids.map((id) => ({ id, quantity: 1, pNow: toPiastres(PRICE[id]), pFull: toPiastres(PRICE[id]) }))), pct, method).totalPay;

describe('calculation method', () => {
  it('company gifts the cheapest half', () => {
    expect(pay(['A', 'B', 'C', 'D'], 30, 'company')).toBe(230000); // gifts D, C
    expect(pay(['A', 'B', 'C'], 30, 'company')).toBe(197000); // gift C, B at 30%
  });
  it('customer gifts the best pairing', () => {
    expect(pay(['A', 'B', 'C', 'D'], 30, 'customer')).toBe(210000);
    expect(pay(['A', 'B', 'C'], 30, 'customer')).toBe(183000);
  });
  it('defaults to customer', () => {
    const units = expandUnits(P([{ id: 'A', quantity: 1, pNow: 1200, pFull: 1200 }, { id: 'B', quantity: 1, pNow: 1100, pFull: 1100 }, { id: 'C', quantity: 1, pNow: 900, pFull: 900 }, { id: 'D', quantity: 1, pNow: 800, pFull: 800 }]));
    expect(computeOffer(units, 30).totalPay).toBe(210000);
  });
});

describe.each(cases.filter((c) => c.company))('$name (company)', (c) => {
  it('pays the expected total and saving', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'company');
    expect(r.totalPay).toBe(toPiastres(c.company.expectedPay));
    expect(r.totalDiscount).toBe(toPiastres(c.company.expectedSaving));
  });
  it('single lands on the expected unit', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'company');
    expect(r.singleUnit?.lineId ?? null).toBe(c.company.expectedSingle ?? null);
  });
  it('per-line plan matches', () => {
    const plan = Object.fromEntries(planLineDiscounts(P(c.lines), c.pct, 'company').map(p => [p.lineId, p]));
    expect(Object.keys(plan).sort()).toEqual(Object.keys(c.company.perLine).sort());
    for (const [id, exp] of Object.entries(c.company.perLine)) {
      expect(plan[id]).toMatchObject({ giftQty: exp.giftQty, giftAmount: toPiastres(exp.giftAmount), singleQty: exp.singleQty, singleAmount: toPiastres(exp.singleAmount) });
    }
  });
});

describe('planLineDiscounts', () => {
  it('omits lines with nothing to discount', () => {
    const plan = planLineDiscounts(P([{ id: 'A', quantity: 1, pNow: 650, pFull: 1000 }]), 30);
    expect(plan).toEqual([]);
  });
});

it('sorts by pNow, then pFull, then lineId', () => {
  const units = [
    { lineId: 'b', pNow: 900, pFull: 900 },
    { lineId: 'a', pNow: 900, pFull: 1000 },
    { lineId: 'z', pNow: 500, pFull: 1000 },
  ];
  expect(sortUnits(units).map(u => u.lineId)).toEqual(['z', 'b', 'a']);
});

it('rounds the single target half-up in piastres', () => {
  expect(singleDiscount({ lineId: 'A', pNow: 100001, pFull: 100001 }, 30)).toBe(30000);
});

describe('money', () => {
  it('converts to piastres half-up', () => {
    expect(toPiastres('1200.0')).toBe(120000);
    expect(toPiastres('0.005')).toBe(1);
    expect(toPiastres(1200)).toBe(120000);
  });
  it('converts to minor units for any decimals', () => {
    expect(toMinor('12.345', 3)).toBe(12345);
    expect(toMinor('1500', 0)).toBe(1500);
    expect(toMinor('1500.5', 0)).toBe(1501);
    expect(toMinor('0.0005', 3)).toBe(1);
    expect(toMinor(1.235, 3)).toBe(1235);
    expect(toMinor('-1.50', 2)).toBe(-150);
  });
  it('formats minor units for any decimals', () => {
    expect(toAmountString(12345, 3)).toBe('12.345');
    expect(toAmountString(5, 3)).toBe('0.005');
    expect(toAmountString(1500, 0)).toBe('1500');
    expect(toAmountString(-1500, 0)).toBe('-1500');
  });
  it('knows each currency decimals', () => {
    expect(decimalsFor('KWD')).toBe(3);
    expect(decimalsFor('JPY')).toBe(0);
    expect(decimalsFor('EGP')).toBe(2);
    expect(decimalsFor('XXX')).toBe(2);
    expect(decimalsFor(undefined)).toBe(2);
  });
  it('formats amounts', () => {
    expect(toAmountString(27000)).toBe('270.00');
    expect(toAmountString(120000)).toBe('1200.00');
    expect(toAmountString(5)).toBe('0.05');
  });
});
