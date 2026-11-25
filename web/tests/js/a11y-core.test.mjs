// FIX-06 on the web console: the keyboard and screen-reader help (resources/js/a11y-core.cjs), without a browser: a
// small stand-in document is enough.
//   node --test tests/js/a11y-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/a11y-core.cjs');

/** An element with the attributes, sizes and children the core reads. */
function el(attributes = {}, extra = {}) {
    return {
        attributes: { ...attributes },
        children: [],
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
        hasAttribute(name) { return name in this.attributes; },
        querySelector() { return this.children.find((c) => c.focusable) ?? null; },
        getClientRects() { return this.hidden ? [] : [{}]; },
        focus() { this.focused = true; },
        ...extra,
    };
}

function doc(found) {
    return { querySelectorAll: (selector) => found[selector] ?? [] };
}

test('a grouped list\'s day headings become items of the list, once', () => {
    const today = el({ class: 'fi-ta-group-header' });
    const yesterday = el({ class: 'fi-ta-group-header', role: 'listitem' });
    const d = doc({ [core.GROUP_HEADERS]: [today, yesterday] });
    assert.equal(core.markGroupHeaders(d), 1);
    assert.equal(today.getAttribute('role'), 'listitem');
    assert.equal(core.markGroupHeaders(d), 0);
});

test('a table box that scrolls sideways with nothing to focus takes the focus, and gives it back when it fits', () => {
    const empty = el({}, { scrollWidth: 461, clientWidth: 356 });
    const withLinks = el({}, { scrollWidth: 900, clientWidth: 356 });
    withLinks.children.push({ focusable: true });
    const fits = el({}, { scrollWidth: 356, clientWidth: 356 });
    const own = el({ tabindex: '-1' }, { scrollWidth: 900, clientWidth: 356 });
    const d = doc({ [core.SCROLLERS]: [empty, withLinks, fits, own] });
    assert.equal(core.markScrollers(d), 1);
    assert.equal(empty.getAttribute('tabindex'), '0');
    assert.equal(withLinks.getAttribute('tabindex'), null, 'its links take the focus');
    assert.equal(fits.getAttribute('tabindex'), null);
    assert.equal(own.getAttribute('tabindex'), '-1', 'a tabindex someone else set is left alone');
    // The window widens: it fits, and the focus stop goes.
    empty.scrollWidth = 356;
    assert.equal(core.markScrollers(d), 1);
    assert.equal(empty.getAttribute('tabindex'), null);
    assert.equal(empty.getAttribute('data-el-scroll-focus'), null);
});

test('the arrows move through the rail, round the ends; Home and End go to the ends; other keys do nothing', () => {
    assert.equal(core.railStep(5, 0, 'ArrowDown'), 1);
    assert.equal(core.railStep(5, 4, 'ArrowDown'), 0);
    assert.equal(core.railStep(5, 0, 'ArrowUp'), 4);
    assert.equal(core.railStep(5, -1, 'ArrowDown'), 0);
    assert.equal(core.railStep(5, 2, 'Home'), 0);
    assert.equal(core.railStep(5, 2, 'End'), 4);
    assert.equal(core.railStep(5, 2, 'ArrowRight'), -1);
    assert.equal(core.railStep(5, 2, 'Tab'), -1);
    assert.equal(core.railStep(0, 0, 'ArrowDown'), -1);
});

test('one stop of the rail takes Tab: the one chosen, else the place you are on, else the first', () => {
    const home = el();
    const library = el({ 'aria-current': 'page' });
    const help = el();
    const items = [home, library, help];
    assert.equal(core.rove(items, null), library);
    assert.deepEqual(items.map((i) => i.getAttribute('tabindex')), ['-1', '0', '-1']);
    assert.equal(core.rove(items, help), help);
    assert.deepEqual(items.map((i) => i.getAttribute('tabindex')), ['-1', '-1', '0']);
    // A stop that is no longer there (redrawn): the place you are on again.
    assert.equal(core.rove(items, el()), library);
    assert.equal(core.rove([home, help], null), home);
    assert.equal(core.rove([], null), null);
});

test('the rail\'s stops are its shown links and buttons, in order', () => {
    const shown = el();
    const hidden = el({}, { hidden: true });
    const rail = { querySelectorAll: (selector) => (selector === 'a[href], button:not([disabled])' ? [shown, hidden] : []) };
    assert.deepEqual(core.railItems(rail), [shown]);
});
