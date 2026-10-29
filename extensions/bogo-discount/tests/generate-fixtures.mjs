// Regenerates tests/fixtures/*.json for the shopify-function-test-helpers harness.
// Inputs come from shared/fixtures/bogo-cases.json. Expected outputs come from the case's
// `perLine` plan, or (for cases without one) from HAND_PLANS below, derived by hand from the
// spec rules. Never from running the function under test. Each plan's total is checked
// against the case's expectedSaving.
// Run: node tests/generate-fixtures.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const outDir = path.join(here, 'fixtures');
const cases = JSON.parse(fs.readFileSync(path.join(here, '../../../shared/fixtures/bogo-cases.json'), 'utf8'));
const constants = JSON.parse(fs.readFileSync(path.join(here, '../../../shared/offer-constants.json'), 'utf8'));

const g = (_id, amount) => ({ giftQty: 1, giftAmount: amount, singleQty: 0, singleAmount: 0 });
const s = (amount) => ({ giftQty: 0, giftAmount: 0, singleQty: 1, singleAmount: amount });
const gs = (ga, sa) => ({ giftQty: 1, giftAmount: ga, singleQty: 1, singleAmount: sa });

// Hand-derived plans (EGP) for cases that have no perLine in the shared file.
const HAND_PLANS = {
  abcd: { D: g('D', 800), B: g('B', 1100) },
  abcde: { B: g('B', 1100), D: g('D', 800), E: s(180) },
  optimality_markdowns: { a: g('a', 10), b: g('b', 500) },
  n3: { B: g('B', 1100), C: s(270) },
  n4: { B: g('B', 1100), D: g('D', 800) },
  n5: { B: g('B', 1100), D: g('D', 800), E: s(180) },
  n6: { B: g('B', 1100), D: g('D', 800), E: g('E', 600) },
  n7: { B: g('B', 1100), D: g('D', 800), E: g('E', 600), G: s(150) },
  n8: { B: g('B', 1100), D: g('D', 800), E: g('E', 600), H: g('H', 400) },
  n9: { B: g('B', 1100), D: g('D', 800), E: g('E', 600), H: g('H', 400), I: s(90) },
  n10: { B: g('B', 1100), D: g('D', 800), E: g('E', 600), H: g('H', 400), J: g('J', 250) },
  n11: { B: g('B', 1100), D: g('D', 800), E: g('E', 600), H: g('H', 400), J: g('J', 250), K: s(60) },
  n12: { B: g('B', 1100), D: g('D', 800), E: g('E', 600), H: g('H', 400), J: g('J', 250), L: g('L', 150) },
  same2_plus3: { C: g('C', 900), B: g('B', 1100), D: s(240) },
  same2_plus4: { C: g('C', 900), B: g('B', 1100), E: g('E', 600) },
  same3_plus2: { C: gs(900, 270), B: g('B', 1100) },
};

const dec = (v) => (Number.isInteger(v) ? `${v}.0` : String(v));
const amountStr = (v) => v.toFixed(2);
const lineGid = (id) => `gid://shopify/CartLine/${id}`;

function buildInput(lines, { classes = ['PRODUCT'], metafield, giftCardIds = [] } = {}) {
  return {
    cart: {
      lines: lines.map((l) => ({
        id: lineGid(l.id),
        quantity: l.quantity,
        cost: {
          amountPerQuantity: { amount: dec(l.pNow) },
          compareAtAmountPerQuantity: l.pFull > l.pNow ? { amount: dec(l.pFull) } : null,
        },
        merchandise: {
          __typename: 'ProductVariant',
          id: `gid://shopify/ProductVariant/${l.id}`,
          product: { id: `gid://shopify/Product/${l.id}`, isGiftCard: giftCardIds.includes(l.id) },
        },
      })),
    },
    discount: { discountClasses: classes },
    shop: { metafield },
  };
}

const cfg = (obj) => ({ value: JSON.stringify(obj) });

function buildOutput(lines, plan, pct) {
  const candidates = [];
  const target = (id, quantity) => [{ cartLine: { id: lineGid(id), quantity } }];
  const value = (amount) => ({ fixedAmount: { amount: amountStr(amount), appliesToEachItem: false } });
  for (const l of lines) {
    const p = plan[l.id];
    if (!p) continue;
    if (p.giftQty) candidates.push({ message: constants.giftMessage, targets: target(l.id, p.giftQty), value: value(p.giftAmount) });
    if (p.singleQty) {
      candidates.push({
        message: constants.percentMessageTemplate.replace('{pct}', String(pct)),
        targets: target(l.id, p.singleQty),
        value: value(p.singleAmount),
      });
    }
  }
  return { operations: [{ productDiscountsAdd: { selectionStrategy: 'ALL', candidates } }] };
}

const fixture = (input, output) => ({
  payload: { export: 'cart-lines-discounts-generate-run', target: 'cart.lines.discounts.generate.run', input, output },
});

const files = {};
const EMPTY = { operations: [] };
const byName = Object.fromEntries(cases.map((c) => [c.name, c]));

for (const c of cases) {
  if (c.lines.length === 0 || c.name === 'markdown_single_650') continue;
  const plan = c.perLine ?? HAND_PLANS[c.name];
  if (!plan) throw new Error(`no expected plan for ${c.name}`);
  const total = Object.values(plan).reduce((sum, p) => sum + p.giftAmount + p.singleAmount, 0);
  if (total !== c.expectedSaving) throw new Error(`${c.name}: plan saves ${total}, case says ${c.expectedSaving}`);
  files[c.name] = fixture(buildInput(c.lines, { metafield: cfg({ enabled: true, percent: c.pct }) }), buildOutput(c.lines, plan, c.pct));
}

const A = byName.a.lines;
const meta30 = cfg({ enabled: true, percent: 30 });
files.disabled = fixture(buildInput(A, { metafield: cfg({ enabled: false, percent: 30 }) }), EMPTY);
files.no_metafield = fixture(buildInput(A, { metafield: null }), EMPTY);
files.percent_0 = fixture(buildInput(A, { metafield: cfg({ enabled: true, percent: 0 }) }), EMPTY);
files.order_class_only = fixture(buildInput(A, { classes: ['ORDER'], metafield: meta30 }), EMPTY);
files.markdown_single_650_alone = fixture(buildInput(byName.markdown_single_650.lines, { metafield: meta30 }), EMPTY);
// Gift card in the cart: only the real product is discountable (alone -> 30% single).
files.giftcard = fixture(
  buildInput(byName.ac.lines, { metafield: meta30, giftCardIds: ['C'] }),
  buildOutput(byName.ac.lines, { A: s(360) }, 30),
);
files.percent_40_single_a = fixture(
  buildInput(A, { metafield: cfg({ enabled: true, percent: 40 }) }),
  buildOutput(A, { A: s(480) }, 40),
);

fs.mkdirSync(outDir, { recursive: true });
for (const f of fs.readdirSync(outDir)) if (f.endsWith('.json')) fs.unlinkSync(path.join(outDir, f));
for (const [name, body] of Object.entries(files)) {
  fs.writeFileSync(path.join(outDir, `${name}.json`), JSON.stringify(body, null, 2) + '\n');
}
console.log(`wrote ${Object.keys(files).length} fixtures to ${outDir}`);
