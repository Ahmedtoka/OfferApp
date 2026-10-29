// Pure BOGO algorithm: buy one get one free, or a lone piece at pct% off.
// Money is integer piastres. No Shopify types in here.

export function expandUnits(lines) {
  const units = [];
  for (const line of lines) {
    for (let i = 0; i < line.quantity; i++) {
      units.push({ lineId: line.id, pNow: line.pNow, pFull: line.pFull });
    }
  }
  return units;
}

export function sortUnits(units) {
  return [...units].sort((a, b) =>
    a.pNow - b.pNow || a.pFull - b.pFull || (a.lineId < b.lineId ? -1 : a.lineId > b.lineId ? 1 : 0));
}

export function singleDiscount(unit, pct) {
  const target = Math.round(unit.pFull * (100 - pct) / 100);
  return Math.max(0, unit.pNow - target);
}

// Pairs sorted units adjacently (cheaper pNow of each pair is the gift).
function pairUp(sorted, assignments) {
  let discount = 0;
  for (let i = 0; i + 1 < sorted.length; i += 2) {
    assignments.push({ unit: sorted[i], role: 'gift', discount: sorted[i].pNow });
    assignments.push({ unit: sorted[i + 1], role: 'paid', discount: 0 });
    discount += sorted[i].pNow;
  }
  return discount;
}

function build(sorted, singleIndex, pct) {
  const assignments = [];
  let singleUnit = null;
  let totalDiscount;
  if (singleIndex === null) {
    totalDiscount = pairUp(sorted, assignments);
  } else {
    singleUnit = sorted[singleIndex];
    const rest = sorted.filter((_, k) => k !== singleIndex);
    const pairs = [];
    const pairDiscount = pairUp(rest, pairs);
    const sd = singleDiscount(singleUnit, pct);
    assignments.push(...pairs, { unit: singleUnit, role: 'single', discount: sd });
    totalDiscount = pairDiscount + sd;
  }
  const sumNow = sorted.reduce((s, u) => s + u.pNow, 0);
  return { assignments, totalDiscount, totalPay: sumNow - totalDiscount, singleUnit };
}

export function computeOffer(units, pct, mode = 'max') {
  const sorted = sortUnits(units);
  if (sorted.length % 2 === 0) return build(sorted, null, pct);

  let best = null;
  for (let i = 0; i < sorted.length; i++) {
    const candidate = { index: i, result: build(sorted, i, pct) };
    if (best === null) { best = candidate; continue; }
    const d = candidate.result.totalDiscount - best.result.totalDiscount;
    const better = mode === 'min' ? d < 0 : d > 0;
    // Ties: smaller pFull wins, then smaller sorted index (already the earlier candidate).
    const tieWin = d === 0 && sorted[i].pFull < sorted[best.index].pFull;
    if (better || tieWin) best = candidate;
  }
  return best.result;
}

export function planLineDiscounts(lines, pct) {
  const { assignments } = computeOffer(expandUnits(lines), pct, 'max');
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
