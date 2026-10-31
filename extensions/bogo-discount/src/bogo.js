// Pure BOGO algorithm: buy one get one free, or a lone piece at pct% off.
// Money is integer piastres. No Shopify types in here.

export function expandUnits(lines) {
  const units = [];
  for (const line of lines) {
    // Units of one line are identical and never mutated, so they share one object.
    const unit = { lineId: line.id, pNow: line.pNow, pFull: line.pFull };
    for (let i = 0; i < line.quantity; i++) units.push(unit);
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

// Methods: 'customer' gives the shopper the best saving (neighbours pair, the cheaper of each pair
// is the gift); 'company' gives the least: the cheapest half of the cart are the gifts and the
// dearer half pay. Both leave one lone piece at pct% off in an odd cart.

// Pairs sorted units adjacently, skipping index `skip` (cheaper pNow of each pair is the gift).
function pairUp(sorted, skip, assignments) {
  let discount = 0;
  let first = null;
  for (let k = 0; k < sorted.length; k++) {
    if (k === skip) continue;
    if (first === null) { first = sorted[k]; continue; }
    assignments.push({ unit: first, role: 'gift', discount: first.pNow });
    assignments.push({ unit: sorted[k], role: 'paid', discount: 0 });
    discount += first.pNow;
    first = null;
  }
  return discount;
}

// Company: the first floor(n/2) units of the cart without the single are gifts, the rest pay.
function giftCheapest(sorted, skip, assignments) {
  const giftCount = Math.floor((sorted.length - (skip === -1 ? 0 : 1)) / 2);
  let discount = 0;
  let given = 0;
  for (let k = 0; k < sorted.length; k++) {
    if (k === skip) continue;
    if (given < giftCount) {
      assignments.push({ unit: sorted[k], role: 'gift', discount: sorted[k].pNow });
      discount += sorted[k].pNow;
      given++;
    } else {
      assignments.push({ unit: sorted[k], role: 'paid', discount: 0 });
    }
  }
  return discount;
}

function build(sorted, singleIndex, pct, method) {
  const assignments = [];
  let singleUnit = null;
  const skip = singleIndex ?? -1;
  let totalDiscount = method === 'company'
    ? giftCheapest(sorted, skip, assignments)
    : pairUp(sorted, skip, assignments);
  if (singleIndex !== null) {
    singleUnit = sorted[singleIndex];
    const sd = singleDiscount(singleUnit, pct);
    assignments.push({ unit: singleUnit, role: 'single', discount: sd });
    totalDiscount += sd;
  }
  let sumNow = 0;
  for (let k = 0; k < sorted.length; k++) sumNow += sorted[k].pNow;
  return { assignments, totalDiscount, totalPay: sumNow - totalDiscount, singleUnit };
}

// Odd cart, customer: choose the single in O(n). Removing sorted index i and pairing the rest
// adjacently makes the gifts exactly the even indices k < i plus the odd indices k > i, so each
// candidate's saving is evenBefore[i] + oddAfter[i] + singleDiscount(sorted[i]).
function bestSingleIndex(sorted, pct) {
  const n = sorted.length;
  const oddAfter = new Array(n);
  let acc = 0;
  for (let i = n - 1; i >= 0; i--) {
    oddAfter[i] = acc;
    if (i % 2 === 1) acc += sorted[i].pNow;
  }
  let evenBefore = 0;
  let bestIndex = -1;
  let bestSaving = 0;
  for (let i = 0; i < n; i++) {
    const saving = evenBefore + oddAfter[i] + singleDiscount(sorted[i], pct);
    if (i % 2 === 0) evenBefore += sorted[i].pNow;
    if (bestIndex === -1) { bestIndex = i; bestSaving = saving; continue; }
    const d = saving - bestSaving;
    // Ties: smaller pFull wins, then smaller sorted index (already the earlier candidate).
    const tieWin = d === 0 && sorted[i].pFull < sorted[bestIndex].pFull;
    if (d > 0 || tieWin) { bestIndex = i; bestSaving = saving; }
  }
  return bestIndex;
}

// Odd cart, company: with m = (n-1)/2 gifts, the gifts are the first m units of the cart without
// the single, so with prefix sums the gift total is prefix(m) when i >= m and prefix(m+1) - pNow[i]
// when i < m. The smallest saving wins; ties: smaller pFull, then smaller sorted index.
function leastSingleIndex(sorted, pct) {
  const n = sorted.length;
  const m = (n - 1) / 2;
  let prefix = 0;
  let prefixM = 0;
  for (let k = 0; k <= m; k++) {
    if (k === m) prefixM = prefix;
    prefix += sorted[k].pNow;
  }
  const prefixM1 = prefix;
  let bestIndex = -1;
  let bestSaving = 0;
  for (let i = 0; i < n; i++) {
    const gifts = i >= m ? prefixM : prefixM1 - sorted[i].pNow;
    const saving = gifts + singleDiscount(sorted[i], pct);
    if (bestIndex === -1) { bestIndex = i; bestSaving = saving; continue; }
    const d = saving - bestSaving;
    const tieWin = d === 0 && sorted[i].pFull < sorted[bestIndex].pFull;
    if (d < 0 || tieWin) { bestIndex = i; bestSaving = saving; }
  }
  return bestIndex;
}

export function computeOffer(units, pct, method = 'customer') {
  const sorted = sortUnits(units);
  if (sorted.length % 2 === 0) return build(sorted, null, pct, method);
  const single = method === 'company' ? leastSingleIndex(sorted, pct) : bestSingleIndex(sorted, pct);
  return build(sorted, single, pct, method);
}

// Count of even / odd unit indices k with s <= k < e.
const evenIn = (s, e) => (e <= s ? 0 : Math.floor((e + 1) / 2) - Math.floor((s + 1) / 2));
const oddIn = (s, e) => (e <= s ? 0 : e - s - evenIn(s, e));

// Same plan as aggregating computeOffer(expandUnits(lines), pct, method) per line, but on
// (line, quantity) runs, so the Function's cost does not grow with item quantity. Units of one
// line are identical, so the sorted unit list is the sorted lines expanded in place. Customer:
// inside a run every choice of single saves the same, so only each line's first unit is a
// candidate (later ones tie on pFull and lose to the smaller index). Company: a run's saving only
// changes where it crosses unit m = (n-1)/2, so its first unit and unit m are the candidates.
export function planLineDiscounts(lines, pct, method = 'customer') {
  const sorted = [...lines].sort((a, b) =>
    a.pNow - b.pNow || a.pFull - b.pFull || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));
  const runs = [];
  let n = 0;
  for (const line of sorted) {
    runs.push({ line, s: n, e: n + line.quantity });
    n += line.quantity;
  }

  const company = method === 'company';
  const m = (n - 1) / 2; // company gifts, odd carts
  let single = null; // { run, at: unit index }
  if (n % 2 === 1 && company) {
    // prefix(k): pNow of the k cheapest units, summed over whole runs plus a part of one.
    let prefixM = 0;
    let prefixM1 = 0;
    let before = 0;
    for (const { line, s, e } of runs) {
      if (m >= s && m <= e) prefixM = before + (m - s) * line.pNow;
      if (m + 1 >= s && m + 1 <= e) prefixM1 = before + (m + 1 - s) * line.pNow;
      before += (e - s) * line.pNow;
    }
    let bestSaving = 0;
    for (const run of runs) {
      const { line, s, e } = run;
      const sd = singleDiscount(line, pct);
      for (const at of s < m && m < e ? [s, m] : [s]) {
        if (e <= s) break;
        const saving = (at >= m ? prefixM : prefixM1 - line.pNow) + sd;
        const d = single === null ? 0 : saving - bestSaving;
        if (single === null || d < 0 || (d === 0 && line.pFull < single.run.line.pFull)) {
          single = { run, at };
          bestSaving = saving;
        }
      }
    }
  } else if (n % 2 === 1) {
    const oddAfter = new Array(runs.length);
    let acc = 0;
    for (let j = runs.length - 1; j >= 0; j--) {
      const { line, s, e } = runs[j];
      oddAfter[j] = acc + oddIn(s + 1, e) * line.pNow;
      acc += oddIn(s, e) * line.pNow;
    }
    let evenBefore = 0;
    let bestSaving = 0;
    for (let j = 0; j < runs.length; j++) {
      const run = runs[j];
      const { line, s, e } = run;
      if (e > s) {
        const saving = evenBefore + oddAfter[j] + singleDiscount(line, pct);
        const d = single === null ? 0 : saving - bestSaving;
        if (single === null || d > 0 || (d === 0 && line.pFull < single.run.line.pFull)) {
          single = { run, at: s };
          bestSaving = saving;
        }
      }
      evenBefore += evenIn(s, e) * line.pNow;
    }
  }

  const at = single === null ? n : single.at;
  const byLine = new Map();
  for (const run of runs) {
    const { line, s, e } = run;
    // Customer gifts: even units before the single, odd units after it. Company gifts: the
    // first floor(n/2) units once the single is taken out.
    const gifts = company
      ? Math.max(0, Math.min(e, at, Math.floor(n / 2)) - s) + Math.max(0, Math.min(e, Math.floor(n / 2) + 1) - Math.max(s, at + 1))
      : evenIn(s, Math.min(e, at)) + oddIn(Math.max(s, at + 1), e);
    const giftQty = line.pNow > 0 ? gifts : 0;
    const sd = single !== null && single.run === run ? singleDiscount(line, pct) : 0;
    if (giftQty === 0 && sd <= 0) continue;
    byLine.set(line.id, {
      lineId: line.id,
      giftQty,
      giftAmount: giftQty * line.pNow,
      singleQty: sd > 0 ? 1 : 0,
      singleAmount: sd > 0 ? sd : 0,
    });
  }
  return lines.map(l => byLine.get(l.id)).filter(Boolean);
}
