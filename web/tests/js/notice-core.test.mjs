// The notice rule's decisions (resources/js/notice-core.cjs), without a browser: node --test tests/js/notice-core.test.mjs
// (Pest runs it too: tests/Feature/Admin/NoticesTest.php). docs/DESIGN_SYSTEM.md "Notices".
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/notice-core.cjs');

test('a message waits 6 s, an error 10 s, Undo 5 s, and a notice about something still true has no timer', () => {
    assert.equal(core.durationFor('success', undefined), 6000);
    assert.equal(core.durationFor('success', 6000), 6000);
    assert.equal(core.durationFor('danger', 6000), 10000);
    assert.equal(core.durationFor('danger', undefined), 10000);
    assert.equal(core.durationFor('danger', 3000), 3000);
    assert.equal(core.durationFor('info', 'undo'), 5000);
    assert.equal(core.durationFor('warning', 'persistent'), null);
    assert.equal(core.durationFor('success', 6000, true), null);
});

test('a short or vertical move is not a drag, so a click stays a click', () => {
    assert.equal(core.isDrag(0, 0), false);
    assert.equal(core.isDrag(8, 0), false);
    assert.equal(core.isDrag(9, 0), true);
    assert.equal(core.isDrag(-30, 4), true);
    assert.equal(core.isDrag(12, 40), false);
});

test('past a third of its width it leaves that way, a flick leaves too, else it springs back', () => {
    assert.equal(core.outcome(100, 300, 0), 'right');
    assert.equal(core.outcome(-100, 300, 0), 'left');
    assert.equal(core.outcome(99, 300, 0), 'back');
    assert.equal(core.outcome(40, 300, 0.8), 'right');
    assert.equal(core.outcome(-40, 300, -0.8), 'left');
    // A flick back towards the middle is not a leave.
    assert.equal(core.outcome(40, 300, -0.8), 'back');
    assert.equal(core.outcome(0, 300, 1), 'back');
    assert.equal(core.outcome(20, 0, 0), 'back');
});

test('it fades as it is dragged, and never all the way', () => {
    assert.equal(core.opacityFor(0, 300), 1);
    assert.equal(core.opacityFor(150, 300), 0.75);
    assert.equal(core.opacityFor(-300, 300), 0.5);
    assert.equal(core.opacityFor(900, 300), 0.5);
    assert.equal(core.opacityFor(10, 0), 1);
});

test('a trackpad swipe moves it sideways only when the fingers move sideways', () => {
    assert.equal(core.wheelStep(-20, 0), 20);
    assert.equal(core.wheelStep(20, 3), -20);
    assert.equal(core.wheelStep(2, 30), 0);
});

test('a closed banner is remembered per state, the announcement per text', () => {
    assert.equal(core.storageKey('failed-uploads', 'abc'), 'el-notice:failed-uploads:abc');
    assert.notEqual(core.storageKey('failed-uploads', 'abc'), core.storageKey('failed-uploads', 'abd'));
    assert.equal(core.hash('New prices from Dec 1'), core.hash('New prices from Dec 1'));
    assert.notEqual(core.hash('New prices from Dec 1'), core.hash('New prices from Dec 2'));
    assert.equal(core.storageKey('announcement', null), 'el-notice:announcement:');
});

test('Esc closes the newest toast', () => {
    assert.deepEqual(core.newest([{ id: 'a', at: 10 }, { id: 'b', at: 30 }, { id: 'c', at: 20 }]), { id: 'b', at: 30 });
    assert.equal(core.newest([]), null);
});
