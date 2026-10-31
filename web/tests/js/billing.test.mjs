import test from 'node:test';
import assert from 'node:assert/strict';
import { billingView } from '../../public/js/billing.js';

const base = { required: true, active: false, subscribed: false, trialDaysLeft: null, partnerDevelopment: false };

test('no status yet shows nothing', () => {
    assert.deepEqual(billingView(null), { text: null, vars: {}, action: null, hideEnded: false });
});

test('billing not required: says so, no button, ended banner hidden', () => {
    const v = billingView({ ...base, required: false, active: true });
    assert.equal(v.text, 'billing.not_required');
    assert.equal(v.action, null);
    assert.equal(v.hideEnded, true);
});

test('required and not active: subscribe button, ended banner stays', () => {
    const v = billingView(base);
    assert.equal(v.text, 'billing.none');
    assert.equal(v.action, 'subscribe');
    assert.equal(v.hideEnded, false);
});

test('trial days left are shown, no button, ended banner hidden', () => {
    const v = billingView({ ...base, active: true, subscribed: true, trialDaysLeft: 9 });
    assert.equal(v.text, 'billing.trial');
    assert.deepEqual(v.vars, { days: 9 });
    assert.equal(v.action, null);
    assert.equal(v.hideEnded, true);
});

test('after the trial the plan is simply active', () => {
    assert.equal(billingView({ ...base, active: true, subscribed: true, trialDaysLeft: 0 }).text, 'billing.plan_active');
});

test('a development store needs no plan but offers a test subscription', () => {
    const v = billingView({ ...base, active: true, partnerDevelopment: true });
    assert.equal(v.text, 'billing.dev_store');
    assert.equal(v.action, 'test_subscribe');
    assert.equal(v.hideEnded, true);
});

test('a development store that already subscribed shows the plan and no button', () => {
    const v = billingView({ ...base, active: true, partnerDevelopment: true, subscribed: true, trialDaysLeft: 14 });
    assert.equal(v.text, 'billing.trial');
    assert.equal(v.action, null);
});
