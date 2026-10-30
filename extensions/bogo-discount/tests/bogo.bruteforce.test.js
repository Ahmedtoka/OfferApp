import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import { expandUnits, sortUnits, computeOffer, singleDiscount, planLineDiscounts } from '../src/bogo.js';
import { toPiastres } from '../src/money.js';

const cases = JSON.parse(fs.readFileSync(new URL('../../../shared/fixtures/bogo-cases.json', import.meta.url), 'utf8'));

// Best saving over every perfect matching of `units` (gift = lower pNow of each pair).
function bestMatching(units) {
  if (units.length === 0) return 0;
  const [first, ...rest] = units;
  let best = -Infinity;
  for (let j = 0; j < rest.length; j++) {
    const partner = rest[j];
    const others = rest.filter((_, k) => k !== j);
    best = Math.max(best, Math.min(first.pNow, partner.pNow) + bestMatching(others));
  }
  return best;
}

// Enumerate: if odd, every choice of single; then every perfect matching of the rest.
function bruteForceMax(units, pct) {
  if (units.length % 2 === 0) return bestMatching(units);
  let best = -Infinity;
  for (let i = 0; i < units.length; i++) {
    const rest = units.filter((_, k) => k !== i);
    best = Math.max(best, singleDiscount(units[i], pct) + bestMatching(rest));
  }
  return best;
}

function mulberry32(seed) {
  return () => {
    seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function randomUnits(rand) {
  const n = 1 + Math.floor(rand() * 7);
  const units = [];
  while (units.length < n) {
    if (units.length > 0 && rand() < 0.25) {
      units.push({ ...units[Math.floor(rand() * units.length)], lineId: 'D' + units.length });
      continue;
    }
    const pFull = toPiastres(100 + Math.floor(rand() * 1901));
    const pNow = Math.round(pFull * (0.5 + rand() * 0.5));
    units.push({ lineId: 'L' + units.length, pNow, pFull });
  }
  return units.slice(0, n);
}

describe('brute-force optimality', () => {
  it('matches exhaustive enumeration on 300 seeded random carts', () => {
    const rand = mulberry32(20261030);
    for (let t = 0; t < 300; t++) {
      const units = randomUnits(rand);
      const pct = [10, 30, 30, 50][t % 4];
      const r = computeOffer(units, pct, 'max');
      expect(r.totalDiscount, JSON.stringify({ units, pct })).toBe(bruteForceMax(units, pct));
    }
  });

  const small = cases.filter(c => c.lines.reduce((s, l) => s + l.quantity, 0) <= 9);
  it.each(small.map(c => [c.name, c]))('matches exhaustive enumeration for fixture %s', (_name, c) => {
    const lines = c.lines.map(l => ({ ...l, pNow: toPiastres(l.pNow), pFull: toPiastres(l.pFull) }));
    const units = expandUnits(lines);
    expect(computeOffer(units, c.pct, 'max').totalDiscount).toBe(bruteForceMax(units, c.pct));
    expect(bruteForceMax(units, c.pct)).toBe(toPiastres(c.expectedSaving));
  });
});

// Test-only reference: the original O(n^2) odd-cart search (for each candidate single, re-pair
// the rest). The O(n) prefix/suffix search in src must pick the same single and the same plan.
function referencePairUp(sorted, assignments) {
  let discount = 0;
  for (let i = 0; i + 1 < sorted.length; i += 2) {
    assignments.push({ unit: sorted[i], role: 'gift', discount: sorted[i].pNow });
    assignments.push({ unit: sorted[i + 1], role: 'paid', discount: 0 });
    discount += sorted[i].pNow;
  }
  return discount;
}

function referenceBuild(sorted, singleIndex, pct) {
  const assignments = [];
  let singleUnit = null;
  let totalDiscount;
  if (singleIndex === null) {
    totalDiscount = referencePairUp(sorted, assignments);
  } else {
    singleUnit = sorted[singleIndex];
    const rest = sorted.filter((_, k) => k !== singleIndex);
    const pairs = [];
    const pairDiscount = referencePairUp(rest, pairs);
    const sd = singleDiscount(singleUnit, pct);
    assignments.push(...pairs, { unit: singleUnit, role: 'single', discount: sd });
    totalDiscount = pairDiscount + sd;
  }
  const sumNow = sorted.reduce((s, u) => s + u.pNow, 0);
  return { assignments, totalDiscount, totalPay: sumNow - totalDiscount, singleUnit };
}

function referenceComputeOffer(units, pct, mode) {
  const sorted = sortUnits(units);
  if (sorted.length % 2 === 0) return referenceBuild(sorted, null, pct);
  let best = null;
  for (let i = 0; i < sorted.length; i++) {
    const candidate = { index: i, result: referenceBuild(sorted, i, pct) };
    if (best === null) { best = candidate; continue; }
    const d = candidate.result.totalDiscount - best.result.totalDiscount;
    const better = mode === 'min' ? d < 0 : d > 0;
    const tieWin = d === 0 && sorted[i].pFull < sorted[best.index].pFull;
    if (better || tieWin) best = candidate;
  }
  return best.result;
}

// Lines with quantities, so the cart has runs of identical units and many ties.
function randomLines(rand) {
  const target = 1 + Math.floor(rand() * 13);
  const lines = [];
  let units = 0;
  while (units < target) {
    const quantity = Math.min(target - units, 1 + Math.floor(rand() * 4));
    const pFull = toPiastres(100 + 50 * Math.floor(rand() * 20));
    const pNow = rand() < 0.4 ? pFull : Math.round(pFull * (0.5 + 0.1 * Math.floor(rand() * 5)));
    lines.push({ id: 'L' + lines.length, quantity, pNow, pFull });
    units += quantity;
  }
  return lines;
}

describe('O(n) odd-cart search equals the original O(n^2) search', () => {
  it('picks the same plan on 500 seeded random carts up to 13 units (max and min)', () => {
    const rand = mulberry32(4242);
    let odd = 0;
    for (let t = 0; t < 500; t++) {
      const units = expandUnits(randomLines(rand));
      if (units.length % 2 === 1) odd++;
      const pct = [10, 25, 30, 50, 90][t % 5];
      for (const mode of ['max', 'min']) {
        const got = computeOffer(units, pct, mode);
        const want = referenceComputeOffer(units, pct, mode);
        const ctx = JSON.stringify({ units, pct, mode });
        expect(got.totalDiscount, ctx).toBe(want.totalDiscount);
        expect(got.totalPay, ctx).toBe(want.totalPay);
        expect(got.singleUnit, ctx).toBe(want.singleUnit);
        expect(got.assignments, ctx).toEqual(want.assignments);
      }
    }
    expect(odd).toBeGreaterThan(150);
  });
});

// Test-only reference: the original per-unit planLineDiscounts (aggregate the unit plan per line).
function referencePlanLineDiscounts(lines, pct) {
  const { assignments } = referenceComputeOffer(expandUnits(lines), pct, 'max');
  const byLine = new Map();
  for (const { unit, role, discount } of assignments) {
    if (role === 'paid' || discount <= 0) continue;
    const entry = byLine.get(unit.lineId) ?? { lineId: unit.lineId, giftQty: 0, giftAmount: 0, singleQty: 0, singleAmount: 0 };
    if (role === 'gift') { entry.giftQty += 1; entry.giftAmount += discount; }
    else { entry.singleQty = 1; entry.singleAmount += discount; }
    byLine.set(unit.lineId, entry);
  }
  return lines.map(l => byLine.get(l.id)).filter(Boolean);
}

describe('run-based planLineDiscounts equals the original per-unit plan', () => {
  it('matches on 500 seeded random carts up to 13 units', () => {
    const rand = mulberry32(777);
    for (let t = 0; t < 500; t++) {
      const lines = randomLines(rand);
      const pct = [10, 25, 30, 50, 90][t % 5];
      expect(planLineDiscounts(lines, pct), JSON.stringify({ lines, pct })).toEqual(referencePlanLineDiscounts(lines, pct));
    }
  });

  it('matches on 200 seeded random larger carts (big quantities, free items, equal prices)', () => {
    const rand = mulberry32(99);
    for (let t = 0; t < 200; t++) {
      const lines = [];
      const count = 1 + Math.floor(rand() * 6);
      for (let k = 0; k < count; k++) {
        const pFull = rand() < 0.1 ? 0 : toPiastres(100 * (1 + Math.floor(rand() * 4)));
        const pNow = rand() < 0.5 ? pFull : Math.round(pFull * 0.5);
        lines.push({ id: 'X' + Math.floor(rand() * 1000) + '_' + k, quantity: 1 + Math.floor(rand() * 15), pNow, pFull });
      }
      const pct = [10, 30, 50][t % 3];
      expect(planLineDiscounts(lines, pct), JSON.stringify({ lines, pct })).toEqual(referencePlanLineDiscounts(lines, pct));
    }
  });
});
