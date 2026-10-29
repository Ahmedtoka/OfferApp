import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import { expandUnits, sortUnits, singleDiscount, computeOffer, planLineDiscounts } from '../src/bogo.js';
import { toPiastres, toAmountString } from '../src/money.js';

const cases = JSON.parse(fs.readFileSync(new URL('../../../shared/fixtures/bogo-cases.json', import.meta.url), 'utf8'));
const P = (lines) => lines.map(l => ({ ...l, pNow: toPiastres(l.pNow), pFull: toPiastres(l.pFull) }));

describe.each(cases)('$name', (c) => {
  it('pays the expected total and saving', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'max');
    expect(r.totalPay).toBe(toPiastres(c.expectedPay));
    expect(r.totalDiscount).toBe(toPiastres(c.expectedSaving));
  });
  it('gifts and single land on the expected units', () => {
    const r = computeOffer(expandUnits(P(c.lines)), c.pct, 'max');
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
  it.runIf(c.scenario2Pay !== undefined)('scenario 2 pays the least-generous total', () => {
    expect(computeOffer(expandUnits(P(c.lines)), c.pct, 'min').totalPay).toBe(toPiastres(c.scenario2Pay));
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
  it('formats amounts', () => {
    expect(toAmountString(27000)).toBe('270.00');
    expect(toAmountString(120000)).toBe('1200.00');
    expect(toAmountString(5)).toBe('0.05');
  });
});
