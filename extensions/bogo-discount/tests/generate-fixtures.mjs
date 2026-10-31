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
const amountStr = (v, d = 2) => v.toFixed(d);
const lineGid = (id) => `gid://shopify/CartLine/${id}`;

// Every line is tagged with the eligible tag unless its id is in untaggedIds.
function buildInput(lines, { classes = ['PRODUCT'], metafield, giftCardIds = [], untaggedIds = [], currency = 'EGP' } = {}) {
  return {
    cart: {
      cost: { subtotalAmount: { currencyCode: currency } },
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
          product: {
            id: `gid://shopify/Product/${l.id}`,
            isGiftCard: giftCardIds.includes(l.id),
            hasAnyTag: !untaggedIds.includes(l.id),
          },
        },
      })),
    },
    discount: { discountClasses: classes },
    shop: { metafield },
  };
}

const cfg = (obj) => ({ value: JSON.stringify(obj) });

function buildOutput(lines, plan, pct, decimals = 2) {
  const candidates = [];
  const target = (id, quantity) => [{ cartLine: { id: lineGid(id), quantity } }];
  const value = (amount) => ({ fixedAmount: { amount: amountStr(amount, decimals), appliesToEachItem: false } });
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

// Input query variables. In production Shopify reads them from the JSON metafield
// $app:bogo/config on the automatic discount node and evaluates hasAnyTag(tags: $eligibleTags)
// itself, so the input already carries the resulting boolean. Neither the test helpers'
// runFunction nor function-runner / `shopify app function run` accept variables, so each
// fixture records them under payload.inputVariables (ignored by the harness, checked by
// tests/input-query.test.js against the constants default).
const DEFAULT_VARIABLES = { eligibleTags: [constants.eligibleTag] };
const fixture = (input, output, inputVariables = DEFAULT_VARIABLES) => ({
  payload: {
    export: 'cart-lines-discounts-generate-run',
    target: 'cart.lines.discounts.generate.run',
    inputVariables,
    input,
    output,
  },
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

// Eligibility (default tag FallOffer): untagged lines are ignored completely. abc lines: A 1200, B 1100, C 900.
const ABC = byName.abc.lines;
const AB = ABC.filter((l) => l.id !== 'C');
// A tagged + B untagged: A is alone, so it takes the single (30% of 1200); B is untouched.
files.untagged_line_ignored = fixture(
  buildInput(AB, { metafield: meta30, untaggedIds: ['B'] }),
  buildOutput(AB, { A: s(360) }, 30),
);
files.all_untagged = fixture(buildInput(ABC, { metafield: meta30, untaggedIds: ['A', 'B', 'C'] }), EMPTY);
// A, B tagged + C untagged: two eligible units pair, the cheaper (B) is the gift, no single.
files.mixed_tags_3_units = fixture(
  buildInput(ABC, { metafield: meta30, untaggedIds: ['C'] }),
  buildOutput(ABC, { B: g('B', 1100) }, 30),
);

// Merchant-configured tag: with eligibleTags ["WinterOffer"], Shopify resolves hasAnyTag to true
// for WinterOffer-tagged products, so the tagged line gets the offer (alone -> 30% single).
files.custom_tag_name = fixture(
  buildInput(A, { metafield: meta30 }),
  buildOutput(A, { A: s(360) }, 30),
  { eligibleTags: ['WinterOffer'] },
);

// Instruction-budget fixtures (I1): large odd carts. All prices have no markdown (pFull = pNow),
// so every placement of the single leaves the same gift total and the best single is the
// first unit of the most expensive line (largest pct% saving, then smallest index).
// One line of quantity q at 600 EGP, 30%: (q-1)/2 gifts of 600 plus one single saving 180.
for (const q of [27, 51, 99]) {
  const lines = [{ id: 'A', quantity: q, pNow: 600, pFull: 600 }];
  const plan = { A: { giftQty: (q - 1) / 2, giftAmount: 600 * (q - 1) / 2, singleQty: 1, singleAmount: 180 } };
  files[`bulk_qty${q}`] = fixture(buildInput(lines, { metafield: meta30 }), buildOutput(lines, plan, 30));
}
// 5 lines, 199 units: 40x100, 40x200, 40x300, 40x400, 39x500 (EGP), 30%. Single = first 500 unit
// (sorted index 160): units 0..159 pair in place (20 gifts per line), units 161..198 pair as
// (161,162).. giving 19 gifts of 500. Saving 2000+4000+6000+8000+9500+150 = 29650.
{
  const lines = [100, 200, 300, 400, 500].map((p, k) => ({ id: 'ABCDE'[k], quantity: k === 4 ? 39 : 40, pNow: p, pFull: p }));
  const plan = {
    A: { giftQty: 20, giftAmount: 2000, singleQty: 0, singleAmount: 0 },
    B: { giftQty: 20, giftAmount: 4000, singleQty: 0, singleAmount: 0 },
    C: { giftQty: 20, giftAmount: 6000, singleQty: 0, singleAmount: 0 },
    D: { giftQty: 20, giftAmount: 8000, singleQty: 0, singleAmount: 0 },
    E: { giftQty: 19, giftAmount: 9500, singleQty: 1, singleAmount: 150 },
  };
  files.bulk_5lines_199 = fixture(buildInput(lines, { metafield: meta30 }), buildOutput(lines, plan, 30));
}

// Per-currency rounding. KWD (3 decimals): 1.235 at 30% off -> 1.235 - round(0.8645) = 0.370.
files.kwd_single_1235 = fixture(
  buildInput([{ id: 'A', quantity: 1, pNow: 1.235, pFull: 1.235 }], { metafield: meta30, currency: 'KWD' }),
  buildOutput([{ id: 'A' }], { A: s(0.37) }, 30, 3),
);
// JPY (0 decimals): the gift is the whole 1000 yen line price, no decimals.
files.jpy_gift_1000 = fixture(
  buildInput([{ id: 'A', quantity: 2, pNow: 1000, pFull: 1000 }], { metafield: meta30, currency: 'JPY' }),
  buildOutput([{ id: 'A' }], { A: { giftQty: 1, giftAmount: 1000, singleQty: 0, singleAmount: 0 } }, 30, 0),
);

fs.mkdirSync(outDir, { recursive: true });
for (const f of fs.readdirSync(outDir)) if (f.endsWith('.json')) fs.unlinkSync(path.join(outDir, f));
for (const [name, body] of Object.entries(files)) {
  fs.writeFileSync(path.join(outDir, `${name}.json`), JSON.stringify(body, null, 2) + '\n');
}
console.log(`wrote ${Object.keys(files).length} fixtures to ${outDir}`);
