import currencyDecimals from '../../../shared/currency-decimals.json';

// Money helpers. All algorithm math is in integer minor units (1 EGP = 100 piastres, 1 KWD = 1000
// fils, 1 JPY = 1 yen); the number of decimals comes from the cart currency.

/** ISO 4217 currency code -> number of decimals (unknown or missing codes use the default of 2). */
export function decimalsFor(currencyCode) {
  const code = String(currencyCode ?? '').toUpperCase();
  if (currencyDecimals.zero.includes(code)) return 0;
  if (currencyDecimals.three.includes(code)) return 3;
  return currencyDecimals.default;
}

/** Decimal string or number -> integer minor units for `decimals`, rounded half-up. */
export function toMinor(value, decimals) {
  const [intPart, fracPart = ''] = String(value).trim().split('.');
  const negative = intPart.startsWith('-');
  const whole = Math.abs(Number(intPart)) * 10 ** decimals;
  const digits = decimals === 0 ? 0 : Number(fracPart.padEnd(decimals, '0').slice(0, decimals));
  const roundUp = fracPart.length > decimals && fracPart[decimals] >= '5' ? 1 : 0;
  const result = whole + digits + roundUp;
  return negative ? -result : result;
}

/** Integer minor units -> amount string with exactly `decimals` decimals, e.g. (12345, 3) -> "12.345". */
export function toAmountString(minor, decimals = 2) {
  const sign = minor < 0 ? '-' : '';
  const abs = Math.abs(minor);
  if (decimals === 0) return `${sign}${abs}`;
  const unit = 10 ** decimals;
  return `${sign}${Math.floor(abs / unit)}.${String(abs % unit).padStart(decimals, '0')}`;
}

/** Two-decimal shorthand kept for existing callers: decimal EGP -> integer piastres. */
export function toPiastres(value) {
  return toMinor(value, 2);
}
