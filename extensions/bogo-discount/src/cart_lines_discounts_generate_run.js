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
  // Legacy shop configs have no method: they run the customer calculation. Any other value
  // than the two known methods fails closed.
  const method = config.method === undefined ? constants.defaultMethod : config.method;
  if (!constants.methods.includes(method)) return null;
  return { percent, method };
}

// Messages are Arabic for an Arabic storefront language and English for everything else.
function messageLanguage(input) {
  return input.localization?.language?.isoCode === 'AR' ? 'ar' : 'en';
}

// Only real products carrying an eligible tag (product.hasAnyTag) or sitting in an eligible
// collection (product.inAnyCollection) take part in the offer. Empty tag and collection lists
// match nothing, so the unused rule stays inert. Every other line is ignored completely: never
// expanded, gifted, discounted or paired.
function isEligible(line) {
  const m = line.merchandise;
  return m?.__typename === 'ProductVariant' && m.product?.isGiftCard === false
    && (m.product?.hasAnyTag === true || m.product?.inAnyCollection === true);
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
  const language = messageLanguage(input);
  const giftMessage = constants.giftMessages[language];
  const singleMessage = constants.percentMessageTemplates[language].replace('{pct}', String(config.percent));
  const candidates = [];
  for (const plan of planLineDiscounts(lines, config.percent, config.method)) {
    if (plan.giftQty > 0) candidates.push(candidate(plan.lineId, plan.giftQty, giftMessage, plan.giftAmount, decimals));
    if (plan.singleQty > 0) candidates.push(candidate(plan.lineId, plan.singleQty, singleMessage, plan.singleAmount, decimals));
  }
  if (candidates.length === 0) return NO_OPERATIONS;
  return { operations: [{ productDiscountsAdd: { selectionStrategy: 'ALL', candidates } }] };
}
