import test from 'node:test';
import assert from 'node:assert/strict';
import { toIso, fromIso, stateKey, checklist, themeEditorUrl, isShopHost } from '../../public/js/lib.js';

test('toIso converts a datetime-local value to ISO 8601 with a timezone, and empty to null', () => {
    const iso = toIso('2026-11-01T10:30');
    assert.match(iso, /Z$/);
    assert.equal(new Date(iso).getTime(), new Date('2026-11-01T10:30').getTime());
    assert.equal(toIso(''), null);
    assert.equal(toIso(null), null);
    assert.equal(toIso('garbage'), null);
});

test('fromIso gives a datetime-local value in local time and round-trips', () => {
    const local = '2026-11-01T10:30';
    assert.equal(fromIso(toIso(local)), local);
    assert.equal(fromIso(null), '');
    assert.equal(fromIso('nope'), '');
});

test('stateKey maps known states and falls back to off', () => {
    for (const s of ['off', 'scheduled', 'running', 'ended']) assert.equal(stateKey(s), s);
    assert.equal(stateKey('x'), 'off');
    assert.equal(stateKey(undefined), 'off');
});

test('checklist is computed from saved state, with manual marks for the rest', () => {
    const empty = { settings: { appliesTo: 'tag', eligibleTag: '', collectionId: null, percent: 30, enabled: false }, state: 'off' };
    let c = checklist(empty, {});
    assert.deepEqual(c.map((i) => i.id), ['products', 'percent', 'test', 'theme', 'on']);
    assert.deepEqual(c.map((i) => i.done), [false, false, false, false, false]);

    const saved = { settings: { appliesTo: 'tag', eligibleTag: 'Sale', collectionId: null, percent: 30, enabled: true, updated_at: '2026-10-31T00:00:00Z' }, state: 'running' };
    c = checklist(saved, { test: true, theme: true });
    assert.deepEqual(c.map((i) => i.done), [true, true, true, true, true]);

    const coll = { settings: { appliesTo: 'collection', eligibleTag: '', collectionId: 'gid://shopify/Collection/1', percent: 30, enabled: false }, state: 'off' };
    assert.equal(checklist(coll, {})[0].done, true);
});

test('themeEditorUrl follows the documented deep link', () => {
    const url = themeEditorUrl('shop-1.myshopify.com', 'KEY', 'app-embed');
    assert.equal(url, 'https://shop-1.myshopify.com/admin/themes/current/editor?context=apps&activateAppId=KEY/app-embed');
});


test('isShopHost accepts only myshopify.com hosts', () => {
    assert.equal(isShopHost('shop-1.myshopify.com'), true);
    assert.equal(isShopHost('evil.com'), false);
    assert.equal(isShopHost('shop.myshopify.com.evil.com'), false);
    assert.equal(isShopHost('a/b.myshopify.com'), false);
    assert.equal(isShopHost(null), false);
});
