// The one-sentence result on the Analytics page. Pure: strings come in through t(key, vars), numbers through fmt(n).
// Describes numbers only; says "won" only when profit is known and positive.

const round1 = (n) => Math.round(n * 10) / 10;

/**
 * @param {object} a  the /api/analytics payload
 * @param {(key: string, vars?: object) => string} t
 * @param {(n: number) => string} [fmt]  the page's number formatter (locale digits)
 */
export function summarySentence(a, t, fmt = String) {
    const discount = fmt(round1(Number(a.discountPctOfSales) || 0));
    const offer = a.aovOffer;
    const other = a.aovOther;
    let sentence;
    if (typeof offer === 'number' && typeof other === 'number' && other > 0) {
        const diff = round1(((offer - other) * 100) / other);
        sentence = diff === 0
            ? t('analytics.summary.same_aov', { discount })
            : t('analytics.summary.with_aov', {
                aov: fmt(Math.abs(diff)),
                dir: t(diff > 0 ? 'analytics.summary.more' : 'analytics.summary.less'),
                discount,
            });
    } else {
        sentence = t('analytics.summary.without_aov', { discount });
    }
    if (a.profit && Number(a.profit.amount) > 0) {
        sentence += ` ${t('analytics.summary.won', { covered: fmt(round1(Number(a.profit.coveredPct) || 0)) })}`;
    }
    return sentence;
}
