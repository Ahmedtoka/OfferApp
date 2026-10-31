import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, statSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const root = new URL('../../extensions/pairly-theme/', import.meta.url);
const { nextStepMessage } = require('../../extensions/pairly-theme/assets/pairly.js');
const load = (f) => JSON.parse(readFileSync(new URL(f, root), 'utf8')).pairly;
const en = load('locales/en.default.json');
const ar = load('locales/ar.json');

test('0 eligible units: no message', () => {
  assert.equal(nextStepMessage(0, 100, en), null);
});

test('1 unit at 100%: add one more and get it free', () => {
  assert.equal(nextStepMessage(1, 100, en), 'Add one more and get it free');
  assert.equal(nextStepMessage(1, 100, ar), ar.free_next);
});

test('1 unit below 100%: the percentage is named', () => {
  assert.equal(nextStepMessage(1, 50, en), 'Add one more and get 50% off it');
  assert.match(nextStepMessage(1, 50, ar), /50/);
});

test('2 units: the pair is complete, no message', () => {
  assert.equal(nextStepMessage(2, 100, en), null);
});

test('3 units: same as 1 unit', () => {
  assert.equal(nextStepMessage(3, 100, en), nextStepMessage(1, 100, en));
  assert.equal(nextStepMessage(5, 100, ar), nextStepMessage(1, 100, ar));
});

test('offer off (no usable percentage) or bad input: no message', () => {
  assert.equal(nextStepMessage(1, 0, en), null);
  assert.equal(nextStepMessage(1, null, en), null);
  assert.equal(nextStepMessage(1, 100, null), null);
  assert.equal(nextStepMessage(-1, 100, en), null);
  assert.equal(nextStepMessage(NaN, 100, en), null);
});

test('Arabic and English define the same keys, Arabic differs', () => {
  assert.deepEqual(Object.keys(ar).sort(), Object.keys(en).sort());
  assert.notEqual(ar.free_next, en.free_next);
});

test('JS and CSS stay under 10 KB each', () => {
  for (const f of ['assets/pairly.js', 'assets/pairly.css']) {
    assert.ok(statSync(new URL(f, root)).size < 10 * 1024, f);
  }
});

test('no external requests and no promotion in the extension files', () => {
  for (const f of ['assets/pairly.js', 'assets/pairly.css', 'blocks/app-embed.liquid', 'blocks/product-badge.liquid', 'snippets/pairly-eligible.liquid']) {
    const text = readFileSync(new URL(f, root), 'utf8');
    assert.doesNotMatch(text, /https?:\/\/(?!www\.w3\.org)/i, f);
  }
});
