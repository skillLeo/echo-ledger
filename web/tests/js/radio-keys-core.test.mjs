// The settings' radio groups (the profile's colours and language, Appearance's rows): their keys, without a browser
// (resources/js/radio-keys-core.cjs).
//   node --test tests/js/radio-keys-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/radio-keys-core.cjs');

test('arrows move the choice round the group; Home and End go to its ends', () => {
    assert.equal(core.next(8, 2, 'ArrowRight', false), 3);
    assert.equal(core.next(8, 7, 'ArrowRight', false), 0);
    assert.equal(core.next(8, 0, 'ArrowLeft', false), 7);
    assert.equal(core.next(8, 3, 'ArrowDown', false), 4);
    assert.equal(core.next(8, 3, 'ArrowUp', false), 2);
    assert.equal(core.next(8, 5, 'Home', false), 0);
    assert.equal(core.next(8, 1, 'End', false), 7);
    // Two choices (English, Urdu): either arrow takes the other one.
    assert.equal(core.next(2, 0, 'ArrowRight', false), 1);
    assert.equal(core.next(2, 1, 'ArrowRight', false), 0);
});

test('right to left, → goes back and ← forward, as the row reads; up and down stay', () => {
    assert.equal(core.next(8, 2, 'ArrowLeft', true), 3);
    assert.equal(core.next(8, 2, 'ArrowRight', true), 1);
    assert.equal(core.next(8, 2, 'ArrowDown', true), 3);
});

test('any other key is not the group\'s', () => {
    for (const key of ['Enter', ' ', 'Tab', 'a', 'PageDown']) assert.equal(core.next(8, 2, key, false), null);
    assert.equal(core.next(0, -1, 'ArrowRight', false), null);
});
