import constants from '../../../shared/offer-constants.json';
import { planLineDiscounts } from './bogo.js';
import { toMinor, toAmountString, decimalsFor } from './money.js';

const NO_OPERATIONS = { operations: [] };

function readConfig(metafield) {
  if (!metafield?.value) return null;
  let config;
  try {
    config = JSON.parse(metafield.value);
  } catch {
    return null;
  }
  const percent = config?.percent;
  if (config?.enabled !== true) return null;
  if (!Number.isInteger(percent) || percent < constants.minPercent || percent > constants.maxPercent) return null;
  return { percent };
}

// Only real products carrying the eligible tag (queried as product.hasAnyTag) take part in the
// offer. Every other line is ignored completely: never expanded, gifted, discounted or paired.
function isEligible(line) {
  const m = line.merchandise;
  return m?.__typename === 'ProductVariant' && m.product?.isGiftCard === false && m.product?.hasAnyTag === true;
}

function discountableLines(cartLines, decimals) {
  return cartLines
    .filter(isEligible)
    .map((l) => {
      const pNow = toMinor(l.cost.amountPerQuantity.amount, decimals);
      const compareAt = l.cost.compareAtAmountPerQuantity?.amount;
      const pCompare = compareAt == null ? 0 : toMinor(compareAt, decimals);
      return { id: l.id, quantity: l.quantity, pNow, pFull: pCompare > pNow ? pCompare : pNow };
    });
}

function candidate(lineId, quantity, message, minor, decimals) {
  return {
    message,
    targets: [{ cartLine: { id: lineId, quantity } }],
    value: { fixedAmount: { amount: toAmountString(minor, decimals), appliesToEachItem: false } },
  };
}

export function cartLinesDiscountsGenerateRun(input) {
  if (!input.discount.discountClasses.includes('PRODUCT')) return NO_OPERATIONS;
  const config = readConfig(input.shop?.metafield);
  if (!config) return NO_OPERATIONS;

  const decimals = decimalsFor(input.cart.cost?.subtotalAmount?.currencyCode);
  const lines = discountableLines(input.cart.lines, decimals);
  const singleMessage = constants.percentMessageTemplate.replace('{pct}', String(config.percent));
  const candidates = [];
  for (const plan of planLineDiscounts(lines, config.percent)) {
    if (plan.giftQty > 0) candidates.push(candidate(plan.lineId, plan.giftQty, constants.giftMessage, plan.giftAmount, decimals));
    if (plan.singleQty > 0) candidates.push(candidate(plan.lineId, plan.singleQty, singleMessage, plan.singleAmount, decimals));
  }
  if (candidates.length === 0) return NO_OPERATIONS;
  return { operations: [{ productDiscountsAdd: { selectionStrategy: 'ALL', candidates } }] };
}
