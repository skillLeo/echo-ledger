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

/** A record element as the browser has it, for the attributes only. */
function fakeRecord() {
    const attrs = new Map();
    return { attrs, setAttribute: (k, v) => attrs.set(k, String(v)), removeAttribute: (k) => attrs.delete(k) };
}
const words = { delete: 'Delete', pin: 'Pin', unpin: 'Unpin' };

test('while a row is dragged its record says which side shows, with the word; letting go clears both', () => {
    const record = fakeRecord();
    for (const dx of [-12, -40, -130]) core.paint(record, core.reveal(dx, false), false, words);
    assert.equal(record.attrs.get('data-swipe'), 'delete');
    assert.equal(record.attrs.get('data-swipe-label'), 'Delete');
    // Back the other way in the same drag: the Pin side.
    core.paint(record, core.reveal(60, false), false, words);
    assert.equal(record.attrs.get('data-swipe'), 'pin');
    assert.equal(record.attrs.get('data-swipe-label'), 'Pin');
    core.paint(record, core.reveal(60, false), true, words);
    assert.equal(record.attrs.get('data-swipe-label'), 'Unpin', 'a pinned row offers Unpin');
    // reset(): nothing left on the record.
    core.paint(record, null);
    assert.equal(record.attrs.has('data-swipe'), false);
    assert.equal(record.attrs.has('data-swipe-label'), false);
});

test('the revealed side is mirrored right to left, like the gesture', () => {
    assert.equal(core.reveal(-50, true), 'pin');
    assert.equal(core.reveal(50, true), 'delete');
    assert.equal(core.reveal(0, false), null);
    const record = fakeRecord();
    core.paint(record, core.reveal(50, true), false, { delete: 'حذف کریں', pin: 'پن کریں', unpin: 'پن ہٹائیں' });
    assert.equal(record.attrs.get('data-swipe'), 'delete');
    assert.equal(record.attrs.get('data-swipe-label'), 'حذف کریں');
    core.paint(null, 'pin', false, words); // no record: nothing to mark, no error
});
