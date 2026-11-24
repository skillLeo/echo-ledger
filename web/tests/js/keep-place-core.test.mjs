// UX-25 on the web console: the Library keeps your place, and Back to top (resources/js/keep-place-core.cjs), without
// a browser.
//   node --test tests/js/keep-place-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/keep-place-core.cjs');
const NOW = 1_800_000_000_000;

test('a place is kept for the same list only: its address with its view, tab, filters and search, for half an hour', () => {
    const saved = core.place({ url: '/app/sessions?tab=calls&f[labels][]=q4', y: 1800, id: 'r7', offset: 240, shown: 100, at: NOW });
    assert.ok(core.restorable(saved, '/app/sessions?tab=calls&f[labels][]=q4', NOW + 60_000));
    // Another tab, other filters or another search: that list starts at its top.
    assert.ok(! core.restorable(saved, '/app/sessions?tab=meetings', NOW + 60_000));
    assert.ok(! core.restorable(saved, '/app/sessions', NOW + 60_000));
    assert.ok(! core.restorable(saved, '/app/sessions?tab=calls&f[labels][]=q4', NOW + 31 * 60_000));
    assert.ok(! core.restorable(null, '/app/sessions', NOW));
    assert.ok(! core.restorable({ url: '/app/sessions' }, '/app/sessions', NOW), 'a place written by something else is no place');
});

test('only coming back from a recording: one was opened after the list was left', () => {
    const saved = core.place({ url: '/app/sessions', y: 1800, id: 'r7', offset: 240, shown: 100, at: NOW });
    assert.ok(core.cameBack(saved, { id: 'r9', at: NOW + 5_000 }));
    assert.ok(! core.cameBack(saved, { id: 'r9', at: NOW - 5_000 }), 'opened before the list was left: from elsewhere');
    assert.ok(! core.cameBack(saved, null));
    assert.ok(! core.cameBack(null, { id: 'r9', at: NOW }));
});

test('the list comes back where it was: the row at the top of the window where it stood, else the nearest place', () => {
    const saved = core.place({ url: '/app/sessions', y: 1800, id: 'r7', offset: 240, shown: 100, at: NOW });
    // That row is drawn 2 100 px down the page now: the page scrolls so that it stands 240 px from the top again.
    assert.equal(core.scrollTo(saved, { rowTop: 2100, maxY: 9000 }), 1860);
    // It is gone (deleted, or no longer in this list): the old place, as near as the page now allows.
    assert.equal(core.scrollTo(saved, { rowTop: null, maxY: 9000 }), 1800);
    assert.equal(core.scrollTo(saved, { rowTop: null, maxY: 600 }), 600);
    assert.equal(core.scrollTo(saved, { rowTop: 100, maxY: 9000 }), 0);
});

test('rows that were shown come back too, in one request, never past the list\'s 500', () => {
    assert.equal(core.showUpTo(100, 50, 500), 100);
    assert.equal(core.showUpTo(260, 50, 500), 260);
    assert.equal(core.showUpTo(50, 50, 500), 0);
    assert.equal(core.showUpTo(900, 50, 500), 500);
});

test('one person\'s place in one workspace: another scope keeps its own', () => {
    assert.deepEqual(core.keys('a1b2c3d4e5f6.0a1b2c3d'), { place: 'el-place:library:a1b2c3d4e5f6.0a1b2c3d', opened: 'el-place:opened:a1b2c3d4e5f6.0a1b2c3d' });
    assert.notEqual(core.keys('p.w1').place, core.keys('p.w2').place);
});

test('the row takes the focus only if nothing else took it while the list came back', () => {
    const body = { name: 'body' };
    const search = { name: 'search' };
    assert.ok(core.focusFree(body, body, body));
    assert.ok(core.focusFree(null, body, body));
    assert.ok(! core.focusFree(search, body, body), 'the person clicked into the search and is typing');
    assert.ok(core.focusFree(search, search, body), 'it was there from the start (the page put it there)');
});

test('the opened row keeps its light tint for a minute after coming back', () => {
    assert.ok(core.tinted(NOW, NOW + 59_000));
    assert.ok(! core.tinted(NOW, NOW + 61_000));
    assert.ok(! core.tinted(null, NOW));
});

test('Back to top shows once the list is more than a screen down, and Home, Ctrl+Home or ⌘↑ go there, never while typing', () => {
    assert.ok(! core.showsBackToTop(700, 800));
    assert.ok(core.showsBackToTop(801, 800));
    const key = (k, more = {}) => ({ key: k, ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, typing: false, ...more });
    assert.ok(core.toTopKey(key('Home'), false));
    assert.ok(core.toTopKey(key('Home', { ctrlKey: true }), false));
    assert.ok(core.toTopKey(key('ArrowUp', { metaKey: true }), true));
    assert.ok(! core.toTopKey(key('ArrowUp', { metaKey: true }), false), '⌘↑ is a Mac\'s');
    assert.ok(! core.toTopKey(key('ArrowUp'), true));
    assert.ok(! core.toTopKey(key('Home', { typing: true }), false));
    assert.ok(! core.toTopKey(key('Home', { shiftKey: true }), false));
    assert.ok(! core.toTopKey(key('Home', { altKey: true }), false));
});

test('what counts as the person doing something: a press, a touch, a key, a wheel turned up or down; never a sideways wheel', () => {
    assert.ok(core.takes({ type: 'pointerdown' }));
    assert.ok(core.takes({ type: 'touchstart' }));
    assert.ok(core.takes({ type: 'keydown' }));
    assert.ok(core.takes({ type: 'wheel', deltaX: 0, deltaY: 120 }));
    assert.ok(core.takes({ type: 'wheel', deltaX: 10, deltaY: -40 }));
    // A Mac's two-finger swipe back is a stream of sideways wheel events, some landing on the page that comes back.
    assert.ok(! core.takes({ type: 'wheel', deltaX: -80, deltaY: 3 }));
    assert.ok(! core.takes({ type: 'wheel', deltaX: 30, deltaY: 0 }));
});

test('the person did something while the list came back: no scroll, no tint and no focus are put back on them', () => {
    // A click on another row (it opens beside the list), a wheel turn, a touch, a key: even where nothing takes the focus.
    assert.deepEqual(core.restoreSteps(true, true), { scroll: false, tint: false, focus: false });
    // Nothing done: the place and the tint, and the focus while nothing else has it.
    assert.deepEqual(core.restoreSteps(false, true), { scroll: true, tint: true, focus: true });
    assert.deepEqual(core.restoreSteps(false, false), { scroll: true, tint: true, focus: false });
});
