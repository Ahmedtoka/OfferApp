import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import { expandUnits, computeOffer, singleDiscount } from '../src/bogo.js';
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
