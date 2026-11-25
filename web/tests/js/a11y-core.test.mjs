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

test('a table box that takes the focus is a region named by its table\'s heading, else its section\'s; it gives both back', () => {
    const around = (headings) => (selector) => {
        const found = headings[selector];
        return found === undefined ? null : { querySelector: (inner) => (inner === found[0] ? { textContent: found[1] } : null) };
    };
    const links = el({}, { scrollWidth: 461, clientWidth: 356, closest: around({ '.fi-ta': ['.fi-ta-header-heading', '\n   Share\n   links  '] }) });
    const inSection = el({}, { scrollWidth: 461, clientWidth: 356, closest: around({ '.fi-ta': ['.fi-ta-header-heading', ''], '.fi-section': ['.fi-section-header-heading', 'Devices'] }) });
    const unnamed = el({}, { scrollWidth: 461, clientWidth: 356, closest: () => null });
    const d = doc({ [core.SCROLLERS]: [links, inSection, unnamed] });
    assert.equal(core.markScrollers(d), 3);
    assert.deepEqual([links.getAttribute('role'), links.getAttribute('aria-label')], ['region', 'Share links']);
    assert.deepEqual([inSection.getAttribute('role'), inSection.getAttribute('aria-label')], ['region', 'Devices']);
    assert.deepEqual([unnamed.getAttribute('tabindex'), unnamed.getAttribute('role'), unnamed.getAttribute('aria-label')], ['0', null, null],
        'no heading: it still takes the focus, never as a region without a name');
    // They fit again: every attribute that was ours goes.
    [links, inSection, unnamed].forEach((box) => { box.scrollWidth = 356; });
    assert.equal(core.markScrollers(d), 3);
    [links, inSection, unnamed].forEach((box) => assert.deepEqual(box.attributes, {}));
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

/**
 * A page with the rail, as install() reads it: the logo, Library (the place you are on), Record's menu button and Help;
 * a stand-in window whose frames run when flush() says and whose observer fires when mutate() says.
 */
function railPage({ hidden = false } = {}) {
    const listeners = new Map();
    const on = (target) => Object.assign(target, {
        addEventListener(type, fn) { listeners.set(`${target.name}:${type}`, [...(listeners.get(`${target.name}:${type}`) ?? []), fn]); },
    });
    const fire = (target, type, event = {}) => (listeners.get(`${target.name}:${type}`) ?? []).forEach((fn) => fn(event));
    const rail = { items: [] };
    rail.querySelectorAll = (selector) => (selector === core.RAIL_ITEMS ? rail.items : []);
    const menu = {};
    const item = (attributes = {}, inMenu = false) => el(attributes, {
        hidden,
        closest(selector) {
            if (selector === core.RAIL) return rail;
            return inMenu && selector === '[role="menu"], .fi-dropdown-panel' ? menu : null;
        },
    });
    const logo = item();
    const library = item({ 'aria-current': 'page' });
    const more = item({ 'aria-haspopup': 'menu', 'aria-expanded': 'false' });
    const help = item();
    rail.items = [logo, library, more, help];
    const document = on({ name: 'document', readyState: 'complete', documentElement: {}, querySelectorAll: () => [], querySelector: (s) => (s === core.RAIL ? rail : null) });
    const frames = [];
    let observer = null;
    const window = on({
        name: 'window',
        requestAnimationFrame: (fn) => frames.push(fn),
        MutationObserver: class { constructor(fn) { observer = fn; } observe() {} },
    });
    const key = (target, k, extra = {}) => {
        const event = { target, key: k, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...extra };
        fire(document, 'keydown', event);
        return event;
    };
    return {
        document, window, rail, logo, library, more, help, item, key,
        stops: () => rail.items.map((i) => i.getAttribute('tabindex')),
        focusin: (target) => fire(document, 'focusin', { target }),
        fire: (type) => fire(document, type),
        mutate: () => observer?.([]),
        flush: () => frames.splice(0).forEach((fn) => fn()),
    };
}

test('install: once per document, the place you are on is the one stop', () => {
    const page = railPage();
    assert.equal(core.install(page.document, page.window), true);
    assert.equal(core.install(page.document, page.window), false);
    assert.deepEqual(page.stops(), ['-1', '0', '-1', '-1']);
});

test('install: the arrows move the stop and the focus through the rail; Home and End go to its ends', () => {
    const page = railPage();
    core.install(page.document, page.window);
    const down = page.key(page.library, 'ArrowDown');
    assert.equal(down.defaultPrevented, true);
    assert.equal(page.more.focused, true);
    assert.deepEqual(page.stops(), ['-1', '-1', '0', '-1']);
    page.key(page.more, 'End');
    assert.deepEqual(page.stops(), ['-1', '-1', '-1', '0']);
    page.key(page.help, 'ArrowDown');
    assert.deepEqual(page.stops(), ['0', '-1', '-1', '-1'], 'round the end');
    assert.equal(page.key(page.logo, 'Enter').defaultPrevented, false, 'Enter is the item\'s own');
});

test('install: the arrows do nothing inside a menu, nor on a trigger whose menu is open', () => {
    const page = railPage();
    core.install(page.document, page.window);
    const record = page.item({ role: 'menuitem' }, true);
    assert.equal(page.key(record, 'ArrowDown').defaultPrevented, false);
    page.more.setAttribute('aria-expanded', 'true');
    assert.equal(page.key(page.more, 'ArrowDown').defaultPrevented, false, 'the open menu takes them');
    assert.equal(page.help.focused, undefined);
    assert.deepEqual(page.stops(), ['-1', '0', '-1', '-1']);
});

test('install: an arrow with a modifier key is left alone', () => {
    const page = railPage();
    core.install(page.document, page.window);
    for (const modifier of ['altKey', 'ctrlKey', 'metaKey', 'shiftKey']) {
        assert.equal(page.key(page.library, 'ArrowDown', { [modifier]: true }).defaultPrevented, false, modifier);
    }
    assert.equal(page.more.focused, undefined);
    assert.deepEqual(page.stops(), ['-1', '0', '-1', '-1']);
});

test('install: focus that lands in the rail (a click, Tab) moves the stop there', () => {
    const page = railPage();
    core.install(page.document, page.window);
    page.focusin(page.help);
    assert.deepEqual(page.stops(), ['-1', '-1', '-1', '0']);
    page.focusin({ closest: () => null });
    assert.deepEqual(page.stops(), ['-1', '-1', '-1', '0'], 'focus outside the rail leaves it');
});

test('install: after a redraw strips the tabindex, the next pass brings back exactly one 0', () => {
    const page = railPage();
    core.install(page.document, page.window);
    page.key(page.library, 'ArrowDown');
    page.rail.items.forEach((i) => i.removeAttribute('tabindex'));
    page.mutate();
    page.mutate();
    page.flush();
    assert.deepEqual(page.stops(), ['-1', '-1', '0', '-1'], 'the stop you moved to');
    // Redrawn with new elements: the place you are on.
    const fresh = [page.item(), page.item({ 'aria-current': 'page' }), page.item()];
    page.rail.items = fresh;
    page.mutate();
    page.flush();
    assert.deepEqual(page.stops(), ['-1', '0', '-1']);
});

test('install: a pass once Alpine has drawn the frame, and a new page\'s stop after wire:navigate', () => {
    const page = railPage({ hidden: true });
    core.install(page.document, page.window);
    assert.deepEqual(page.stops(), [null, null, null, null], 'x-cloak: nothing shown yet');
    page.rail.items.forEach((i) => { i.hidden = false; });
    page.fire('alpine:initialized');
    page.flush();
    assert.deepEqual(page.stops(), ['-1', '0', '-1', '-1']);
    page.focusin(page.help);
    page.fire('livewire:navigated');
    page.flush();
    assert.deepEqual(page.stops(), ['-1', '0', '-1', '-1'], 'the place you are on, not the stop you left');
});
