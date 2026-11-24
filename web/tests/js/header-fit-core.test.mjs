// Decision 18 on the web console's header: what the top bar and the Library's toolbar row give up, in order, when they
// have no room, and when a fit need not run again (resources/js/header-fit-core.cjs). The browser lays each step out
// and answers whether it fits; here a row of widths stands in for it.
//   node --test tests/js/header-fit-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/header-fit-core.cjs');

const GAP = 8;
const TO_TABS = 24;
const LEAST = 180;
const BUTTON = 32;

/** A row [width] px wide holding `parts` (widths in px), laid out as the stylesheet does in each state; logs each try. */
function row(width, parts, tries = []) {
    // The title with its count beside it, or under it; the title's words on more lines as narrow as its longest word.
    const title = (s) => (s.title === 'wraps' ? parts.longestWord : parts.title);
    const head = (s) => (s.count === 'under' ? Math.max(title(s), parts.count) : title(s) + GAP + parts.count);
    const tools = (s) => (s.labels === 'on' ? parts.toolsLabelled : parts.toolsIcons);
    const search = (s) => (s.search === 'field' ? LEAST : BUTTON);
    return (s, part) => {
        tries.push(`${part} ${JSON.stringify(s)}`);
        if (part === 'row') return head(s) + TO_TABS + parts.tabs + GAP + search(s) + GAP + tools(s) <= width;
        if (part === 'line1') return head(s) + GAP + search(s) + GAP + tools(s) <= width;
        return s.tabs === 'scroll' || parts.tabs <= width;
    };
}

// "Library / All recordings", "7 recordings", All · Calls · Meetings · Voice notes, [Filters] [Sort & display].
const library = { title: 162, longestWord: 90, count: 72, tabs: 294, toolsLabelled: 249, toolsIcons: 104 };
const two = (line1, tabs = 'whole') => ({ lines: 2, ...line1, tabs });

test('the top bar keeps its field while 180 px of it fits, then shows the ⌕ button', () => {
    assert.deepEqual(core.topBar(() => true), { search: 'field' });
    assert.deepEqual(core.topBar((s) => s.search === 'button'), { search: 'button' });
    assert.deepEqual(core.topBar(() => false), { search: 'button' });
});

test('the Library row is one line only with every word; short of that it splits, its labels kept', () => {
    // 162 + 8 + 72, 24 to the tabs, 294, 8, 180, 8, 249 = 1005.
    assert.deepEqual(core.library(row(1005, library)), { lines: 1, labels: 'on', search: 'field', count: 'beside', title: 'whole', tabs: 'whole' });
    const tries = [];
    assert.deepEqual(core.library(row(1004, library, tries)), two({ labels: 'on', search: 'field', count: 'beside', title: 'whole' }));
    // One try on one line (never with icons), then the tabs' row, then the title row.
    assert.equal(tries.filter((t) => t.startsWith('row ')).length, 1);
    assert.match(tries[1], /^line2 /);
    assert.match(tries[2], /^line1 .*"labels":"on"/);
});

test('from 1024 px the one line gives up the toolbar\'s labels before it splits (the list within 120 px)', () => {
    // 242, 24, 294, 8, 180, 8 and 104 for the icons = 860.
    assert.deepEqual(core.library(row(1005, library), true), { lines: 1, labels: 'on', search: 'field', count: 'beside', title: 'whole', tabs: 'whole' });
    assert.deepEqual(core.library(row(1004, library), true), { lines: 1, labels: 'off', search: 'field', count: 'beside', title: 'whole', tabs: 'whole' });
    assert.deepEqual(core.library(row(860, library), true), { lines: 1, labels: 'off', search: 'field', count: 'beside', title: 'whole', tabs: 'whole' });
    assert.deepEqual(core.library(row(859, library), true), two({ labels: 'on', search: 'field', count: 'beside', title: 'whole' }));
});

test('on the title row the labels go first, then the count goes under the title, then the field is the ⌕ button', () => {
    // 242 + 8 + 180 + 8 + 249 = 687 with the labels; 542 with icons.
    assert.deepEqual(core.library(row(687, library)), two({ labels: 'on', search: 'field', count: 'beside', title: 'whole' }));
    assert.deepEqual(core.library(row(686, library)), two({ labels: 'off', search: 'field', count: 'beside', title: 'whole' }));
    assert.deepEqual(core.library(row(542, library)), two({ labels: 'off', search: 'field', count: 'beside', title: 'whole' }));
    // 162 + 8 + 180 + 8 + 104 = 462 with the count under the title.
    assert.deepEqual(core.library(row(541, library)), two({ labels: 'off', search: 'field', count: 'under', title: 'whole' }));
    assert.deepEqual(core.library(row(462, library)), two({ labels: 'off', search: 'field', count: 'under', title: 'whole' }));
    // 162 + 8 + 32 + 8 + 104 = 314 with the button: a phone's 358 px row keeps every tab whole on its own row.
    assert.deepEqual(core.library(row(461, library)), two({ labels: 'off', search: 'button', count: 'under', title: 'whole' }));
    assert.deepEqual(core.library(row(358, library)), two({ labels: 'off', search: 'button', count: 'under', title: 'whole' }));
    assert.deepEqual(core.library(row(314, library)), two({ labels: 'off', search: 'button', count: 'under', title: 'whole' }));
});

test('only when even the button leaves no room does the title take more lines, its words all kept', () => {
    assert.deepEqual(core.library(row(313, library)), two({ labels: 'off', search: 'button', count: 'under', title: 'wraps' }));
    // A long view name on a phone: more lines for the title; the tabs keep their own row, whole.
    const long = { ...library, title: 330, longestWord: 110 };
    assert.deepEqual(core.library(row(358, long)), two({ labels: 'off', search: 'button', count: 'under', title: 'wraps' }));
});

test('the tabs have a row of their own, which scrolls only when the tabs alone do not fit it', () => {
    assert.equal(core.library(row(294, library)).tabs, 'whole');
    assert.equal(core.library(row(293, library)).tabs, 'scroll');
    // A long title does not touch the tabs' row.
    assert.equal(core.library(row(700, { ...library, title: 600 })).tabs, 'whole');
});

test('a fit runs again only when the rows\' widths, their words or the language change, or when it is forced', () => {
    const needed = core.gate();
    const parts = { bar: 1108, row: 1060, words: 'Library / All recordings|7 recordings|All 7 Calls 4|Filters', lang: 'en ltr' };
    assert.equal(needed(parts), true);
    // A redraw that changed nothing (a keystroke's answer, the list's poll): no layout at all.
    assert.equal(needed({ ...parts }), false);
    assert.equal(needed({ ...parts }), false);
    assert.equal(needed({ ...parts, row: 900 }), true);
    assert.equal(needed({ ...parts, row: 900 }), false);
    assert.equal(needed({ ...parts, row: 900, words: parts.words.replace('All 7', 'All 10') }), true);
    assert.equal(needed({ ...parts, row: 900, words: parts.words.replace('All 7', 'All 10'), lang: 'ur rtl' }), true);
    // The fonts came: the same words, another width; forced.
    assert.equal(needed({ ...parts, row: 900, words: parts.words.replace('All 7', 'All 10'), lang: 'ur rtl' }, true), true);
    // Each fit has its own gate.
    assert.equal(core.gate()(parts), true);
});

test('the steps are in the order of decision 18 and its review', () => {
    assert.deepEqual(core.TOP_BAR, [{ search: 'field' }, { search: 'button' }]);
    assert.deepEqual(core.ONE_LINE, { labels: 'on', search: 'field', count: 'beside', title: 'whole', tabs: 'whole' });
    assert.deepEqual(core.LINE_1.map((s) => [s.labels, s.count, s.search, s.title].join(' ')),
        ['on beside field whole', 'off beside field whole', 'off under field whole', 'off under button whole', 'off under button wraps']);
    assert.deepEqual(core.LINE_2, [{ tabs: 'whole' }, { tabs: 'scroll' }]);
});
