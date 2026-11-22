// The v2.0 recording page's moment markers on the player bar (resources/js/recording-markers-core.cjs), without a
// browser: node --test tests/js/recording-markers-core.test.mjs (Pest runs it too: tests/Feature/V2/RecordingPageV2Test.php).
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/recording-markers-core.cjs');

const d = {
    markers: [{ at_ms: 65000, tag: 'Decision', note: 'Forty percent advance' }, { at_ms: 300000, tag: null, note: null }],
    pauses: [{ at_ms: 120000, paused_ms: 30000 }],
};
const words = { bookmark: 'Bookmark', paused: 'Paused at' };

test('a bookmark tick is the bookmark of its place among the bookmarks, and plays from its moment', () => {
    assert.deepEqual(core.moment(d, false, 0), { ms: 65000, kind: 'bookmark', tag: 'Decision' });
    assert.deepEqual(core.moment(d, false, 1), { ms: 300000, kind: 'bookmark', tag: null });
});

test('a pause tick is the pause of its place among the pauses', () => {
    assert.deepEqual(core.moment(d, true, 0), { ms: 120000, kind: 'pause', tag: null });
});

test('a tick with nothing behind it (the bar drawn again meanwhile) does nothing', () => {
    assert.equal(core.moment(d, false, 5), null);
    assert.equal(core.moment(d, true, -1), null);
    assert.equal(core.moment({}, false, 0), null);
});

test('hovering says what it is, with the player\'s clock', () => {
    assert.equal(core.label(core.moment(d, false, 0), words), 'Decision · 1:05');
    assert.equal(core.label(core.moment(d, false, 1), words), 'Bookmark · 5:00');
    assert.equal(core.label(core.moment(d, true, 0), words), 'Paused at 2:00');
    assert.equal(core.clock(3_725_000), '1:02:05');
    assert.equal(core.label(null, words), '');
});
