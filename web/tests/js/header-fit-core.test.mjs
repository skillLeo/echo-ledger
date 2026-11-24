// Decision 18 on the web console's header: what the top bar and the Library's toolbar row give up, in order, when they
// have no room (resources/js/header-fit-core.cjs). The browser lays each step out and answers whether it fits; here a
// row of widths stands in for it.
//   node --test tests/js/header-fit-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/header-fit-core.cjs');

const GAP = 8;
const LEAST = 180;
const BUTTON = 32;

/** A row [width] px wide holding `parts` (widths in px), laid out as the stylesheet does in each state; logs each try. */
function row(width, parts, tries = []) {
    const sum = (list) => list.reduce((a, b) => a + b, 0) + GAP * (list.length - 1);
    const tools = (s) => (s.labels === 'on' ? parts.toolsLabelled : parts.toolsIcons);
    const search = (s) => (s.search === 'field' ? LEAST : BUTTON);
    // The title: whole on one line, or (the last step) its words on more lines, as narrow as its longest word.
    const title = (s) => (s.title === 'wraps' ? parts.longestWord : parts.title);
    return (s, part) => {
        tries.push(`${part} ${JSON.stringify(s)}`);
        if (part === 'row') return sum([title(s), parts.count, parts.tabs, search(s), tools(s)]) <= width;
        if (part === 'line1') return sum([title(s), parts.count, search(s)]) <= width;
        return sum([s.tabs === 'scroll' ? parts.tabStep : parts.tabs, tools(s)]) <= width;
    };
}

// "Library / All recordings", "7 recordings", All · Calls · Meetings · Voice notes, [Filters] [Sort & display].
const library = { title: 162, longestWord: 90, count: 71, tabs: 294, tabStep: 60, toolsLabelled: 249, toolsIcons: 110 };
// "Library / Unread": line 1 holds the field down to 367 px.
const unread = { ...library, title: 100 };

test('the top bar keeps its field while 180 px of it fits, then shows the ⌕ button', () => {
    assert.deepEqual(core.topBar(() => true), { search: 'field' });
    assert.deepEqual(core.topBar((s) => s.search === 'button'), { search: 'button' });
    // Nothing fits: the last step, the button.
    assert.deepEqual(core.topBar(() => false), { search: 'button' });
});

test('the Library row stays one line with its labels while everything fits', () => {
    // 162 + 71 + 294 + 180 + 249 and four gaps = 988.
    assert.deepEqual(core.library(row(988, library)), { lines: 1, labels: 'on', search: 'field', tabs: 'whole', title: 'whole' });
});

test('on one line the toolbar\'s labels go first, before the row splits', () => {
    const tries = [];
    // One px short with labels; with icons it fits (162 + 71 + 294 + 180 + 110 + 32 = 849).
    assert.deepEqual(core.library(row(987, library, tries)), { lines: 1, labels: 'off', search: 'field', tabs: 'whole', title: 'whole' });
    assert.deepEqual(core.library(row(849, library)), { lines: 1, labels: 'off', search: 'field', tabs: 'whole', title: 'whole' });
    assert.equal(tries.length, 2);
    assert.match(tries[0], /^row .*"labels":"on"/);
    assert.match(tries[1], /^row .*"labels":"off"/);
});

test('with no room on one line: two lines, the title and the search, then the tabs and the toolbar', () => {
    // 848: one line no longer fits even with icons. Line 1: 162 + 71 + 180 + 16 = 429; line 2: 294 + 249 + 8 = 551.
    assert.deepEqual(core.library(row(848, library)), { lines: 2, labels: 'on', search: 'field', tabs: 'whole', title: 'whole' });
});

test('on the second line the labels go first, then the tabs scroll sideways', () => {
    assert.deepEqual(core.library(row(551, unread)), { lines: 2, labels: 'on', search: 'field', tabs: 'whole', title: 'whole' });
    // 550: the labels no longer fit beside the tabs; icons do (294 + 110 + 8 = 412).
    assert.deepEqual(core.library(row(550, unread)), { lines: 2, labels: 'off', search: 'field', tabs: 'whole', title: 'whole' });
    assert.deepEqual(core.library(row(412, unread)), { lines: 2, labels: 'off', search: 'field', tabs: 'whole', title: 'whole' });
    // 411: the tabs scroll; the labels stay icons.
    assert.deepEqual(core.library(row(411, unread)), { lines: 2, labels: 'off', search: 'field', tabs: 'scroll', title: 'whole' });
});

test('on the first line the field shrinks to 180 px, then it is the ⌕ button; the title never gives way first', () => {
    // 429: the title, the count and 180 px of field; 428: the button (162 + 71 + 32 + 16 = 281).
    assert.equal(core.library(row(429, library)).search, 'field');
    assert.deepEqual(core.library(row(428, library)), { lines: 2, labels: 'off', search: 'button', tabs: 'whole', title: 'whole' });
    assert.deepEqual(core.library(row(281, library)), { lines: 2, labels: 'off', search: 'button', tabs: 'scroll', title: 'whole' });
});

test('only when even the button leaves no room does the title take more lines, its words all kept', () => {
    assert.deepEqual(core.library(row(280, library)), { lines: 2, labels: 'off', search: 'button', tabs: 'scroll', title: 'wraps' });
    // A long view name: the button first (330 + 71 + 32 + 16 = 449), then more lines for the title on a phone.
    const long = { ...library, title: 330, longestWord: 110 };
    assert.deepEqual(core.library(row(450, long)), { lines: 2, labels: 'off', search: 'button', tabs: 'whole', title: 'whole' });
    assert.deepEqual(core.library(row(358, long)), { lines: 2, labels: 'off', search: 'button', tabs: 'scroll', title: 'wraps' });
});

test('the two lines make room each on its own: a long title does not take the second line\'s labels', () => {
    const long = { ...library, title: 600 };
    // Line 1 (600 + 71 + 180 + 16 = 867) has no room for the field; line 2 (551) keeps every label.
    assert.deepEqual(core.library(row(860, long)), { lines: 2, labels: 'on', search: 'button', tabs: 'whole', title: 'whole' });
});

test('the steps are in the order of decision 18', () => {
    assert.deepEqual(core.TOP_BAR, [{ search: 'field' }, { search: 'button' }]);
    assert.deepEqual(core.ONE_LINE, [{ labels: 'on' }, { labels: 'off' }]);
    assert.deepEqual(core.LINE_2, [{ labels: 'on', tabs: 'whole' }, { labels: 'off', tabs: 'whole' }, { labels: 'off', tabs: 'scroll' }]);
    assert.deepEqual(core.LINE_1, [{ search: 'field', title: 'whole' }, { search: 'button', title: 'whole' }, { search: 'button', title: 'wraps' }]);
});
