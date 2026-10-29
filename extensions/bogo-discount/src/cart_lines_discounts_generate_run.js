import constants from '../../../shared/offer-constants.json';
import { planLineDiscounts } from './bogo.js';
import { toPiastres, toAmountString } from './money.js';

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

function discountableLines(cartLines) {
  return cartLines
    .filter((l) => l.merchandise?.__typename === 'ProductVariant' && !l.merchandise.product?.isGiftCard)
    .map((l) => {
      const pNow = toPiastres(l.cost.amountPerQuantity.amount);
      const compareAt = l.cost.compareAtAmountPerQuantity?.amount;
      const pCompare = compareAt == null ? 0 : toPiastres(compareAt);
      return { id: l.id, quantity: l.quantity, pNow, pFull: pCompare > pNow ? pCompare : pNow };
    });
}

function candidate(lineId, quantity, message, piastres) {
  return {
    message,
    targets: [{ cartLine: { id: lineId, quantity } }],
    value: { fixedAmount: { amount: toAmountString(piastres), appliesToEachItem: false } },
  };
}

export function cartLinesDiscountsGenerateRun(input) {
  if (!input.discount.discountClasses.includes('PRODUCT')) return NO_OPERATIONS;
  const config = readConfig(input.shop?.metafield);
  if (!config) return NO_OPERATIONS;

  const lines = discountableLines(input.cart.lines);
  const singleMessage = constants.percentMessageTemplate.replace('{pct}', String(config.percent));
  const candidates = [];
  for (const plan of planLineDiscounts(lines, config.percent)) {
    if (plan.giftQty > 0) candidates.push(candidate(plan.lineId, plan.giftQty, constants.giftMessage, plan.giftAmount));
    if (plan.singleQty > 0) candidates.push(candidate(plan.lineId, plan.singleQty, singleMessage, plan.singleAmount));
  }
  if (candidates.length === 0) return NO_OPERATIONS;
  return { operations: [{ productDiscountsAdd: { selectionStrategy: 'ALL', candidates } }] };
}
