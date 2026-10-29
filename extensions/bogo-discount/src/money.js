// Money helpers. All algorithm math is in integer piastres (1 EGP = 100).

/** Decimal string or number of EGP -> integer piastres, rounded half-up. */
export function toPiastres(value) {
  const [intPart, fracPart = ''] = String(value).trim().split('.');
  const negative = intPart.startsWith('-');
  const whole = Math.abs(Number(intPart)) * 100;
  const digits = (fracPart + '00').slice(0, 2);
  const roundUp = fracPart.length > 2 && fracPart[2] >= '5' ? 1 : 0;
  const result = whole + Number(digits) + roundUp;
  return negative ? -result : result;
}

/** Integer piastres -> amount string with two decimals, e.g. 120000 -> "1200.00". */
export function toAmountString(piastres) {
  const sign = piastres < 0 ? '-' : '';
  const abs = Math.abs(piastres);
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}
