// The v2.0 Library's swipe at phone width (resources/js/library-swipe-core.cjs), without a browser:
// node --test tests/js/library-swipe-core.test.mjs (Pest runs it too: tests/Feature/V2/LibrarySwipeTest.php).
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/library-swipe-core.cjs');

test('only at phone width: up to 720 px, never above', () => {
    assert.equal(core.enabled(390), true);
    assert.equal(core.enabled(720), true);
    assert.equal(core.enabled(721), false);
    assert.equal(core.enabled(1280), false);
});

test('left is Delete and right is Pin, past a quarter of the row (at least 72 px)', () => {
    assert.equal(core.action(-120, 4, 360, false), 'delete');
    assert.equal(core.action(120, -6, 360, false), 'pin');
    assert.equal(core.threshold(360), 90);
    assert.equal(core.threshold(200), 72);
    assert.equal(core.action(-80, 0, 360, false), null, 'too short');
});

test('right to left mirrors the directions', () => {
    assert.equal(core.action(120, 0, 360, true), 'delete');
    assert.equal(core.action(-120, 0, 360, true), 'pin');
});

test('a mostly vertical move is the page scrolling, not a swipe', () => {
    assert.equal(core.intent(4, 3), null);
    assert.equal(core.intent(30, 40), 'scroll');
    assert.equal(core.intent(60, 20), 'swipe');
    assert.equal(core.action(-150, 120, 360, false), null);
});
