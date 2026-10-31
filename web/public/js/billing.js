// What the page shows about the plan. Pure: takes the /api/billing payload, returns string keys for t() and an action.

/**
 * @param {object|null} status  GET /api/billing: { required, active, subscribed, trialDaysLeft, partnerDevelopment }
 * @returns {{text: ?string, vars: object, action: ?('subscribe'|'test_subscribe'), hideEnded: boolean}}
 */
export function billingView(status) {
    if (!status || typeof status !== 'object') {
        return { text: null, vars: {}, action: null, hideEnded: false };
    }
    const active = Boolean(status.active);
    if (!status.required) {
        return { text: 'billing.not_required', vars: {}, action: null, hideEnded: true };
    }
    if (status.subscribed) {
        const days = Number(status.trialDaysLeft);
        return days > 0
            ? { text: 'billing.trial', vars: { days }, action: null, hideEnded: active }
            : { text: 'billing.plan_active', vars: {}, action: null, hideEnded: active };
    }
    if (status.partnerDevelopment) {
        return { text: 'billing.dev_store', vars: {}, action: 'test_subscribe', hideEnded: active };
    }
    if (!active) {
        return { text: 'billing.none', vars: {}, action: 'subscribe', hideEnded: false };
    }
    return { text: 'billing.plan_active', vars: {}, action: null, hideEnded: true };
}
