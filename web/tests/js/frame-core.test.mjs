// The three levels' decisions (resources/js/frame-core.cjs), without a browser:
// node --test tests/js/frame-core.test.mjs (Pest runs it too: tests/Feature/V2/NavFrameTest.php).
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/frame-core.cjs');
const origin = 'https://echoledger.test';
const page = { tagName: 'BODY' };
const key = (k, more = {}) => ({ key: k, code: '', ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, defaultPrevented: false, target: page, ...more });
const frame = (more = {}) => ({ mac: true, modal: false, rail: 6, items: 9, ...more });

test('a rail icon brings you back where you left that place, and starts the place you are in over', () => {
    // Another place: where you left it (its view, filters, page), from this browser's memory.
    assert.deepEqual(core.railTarget({ key: 'library', current: 'projects', home: '/app/sessions', last: '/app/sessions?tab=unread&f[source][op]=is', origin }),
        { go: '/app/sessions?tab=unread&f[source][op]=is', reset: false });
    // Never been there, or nothing kept: its own page.
    assert.deepEqual(core.railTarget({ key: 'ask', current: 'library', home: '/app/ask', last: null, origin }), { go: '/app/ask', reset: false });
    // The place you are in: its own page, the memory dropped (clicking the selected icon resets that place).
    assert.deepEqual(core.railTarget({ key: 'library', current: 'library', home: '/app/sessions', last: '/app/sessions?tab=unread', origin }), { go: '/app/sessions', reset: true });
    // From Home (no place): where you left each one.
    assert.deepEqual(core.railTarget({ key: 'library', current: null, home: '/app/sessions', last: '/app/sessions?v=01J', origin }), { go: '/app/sessions?v=01J', reset: false });
});

test('a remembered address is only ever a page of this console', () => {
    for (const bad of ['https://elsewhere.example/app', '//elsewhere.example/app', 'javascript:alert(1)', 'app/sessions', '', null, 42]) {
        assert.equal(core.sameConsole(bad, origin), false, String(bad));
        assert.deepEqual(core.railTarget({ key: 'library', current: 'ask', home: '/app/sessions', last: bad, origin }), { go: '/app/sessions', reset: false });
    }
    assert.equal(core.sameConsole('/app/sessions?tab=unread#x', origin), true);
});

test('under the flyout width a rail icon opens its place\'s panel over the page, the same icon again closes it', () => {
    assert.equal(core.railFlyout({ narrow: true, hasPanel: true, flyout: null, key: 'library' }), 'open');
    assert.equal(core.railFlyout({ narrow: true, hasPanel: true, flyout: 'library', key: 'library' }), 'close');
    assert.equal(core.railFlyout({ narrow: true, hasPanel: true, flyout: 'library', key: 'projects' }), 'open');
    // Wide: the icon goes to the place; a place without a panel is always followed.
    assert.equal(core.railFlyout({ narrow: false, hasPanel: true, flyout: null, key: 'library' }), 'go');
    assert.equal(core.railFlyout({ narrow: true, hasPanel: false, flyout: null, key: 'home' }), 'go');
});

test('under the flyout width the place you are in starts over from its icon too (its flyout opening or closing with it)', () => {
    assert.equal(core.railFlyout({ narrow: true, hasPanel: true, flyout: null, key: 'library', current: 'library' }), 'restart');
    assert.equal(core.railFlyout({ narrow: true, hasPanel: true, flyout: 'library', key: 'library', current: 'library' }), 'restart');
    // Another place still opens its flyout; wide, the place you are in starts over as always.
    assert.equal(core.railFlyout({ narrow: true, hasPanel: true, flyout: null, key: 'projects', current: 'library' }), 'open');
    assert.equal(core.railFlyout({ narrow: false, hasPanel: true, flyout: null, key: 'library', current: 'library' }), 'go');
    // Starting over: its own page, the memory dropped (All recordings, no filter).
    assert.deepEqual(core.railTarget({ key: 'library', current: 'library', home: '/app/sessions', last: '/app/sessions?f[source][op]=is', origin }), { go: '/app/sessions', reset: true });
});

test('a closed flyout gives focus back to what opened it after Esc and ⊟, after a click outside only when focus was left nowhere', () => {
    assert.equal(core.flyoutFocusBack('escape', false), true);
    assert.equal(core.flyoutFocusBack('toggle', false), true);
    assert.equal(core.flyoutFocusBack('outside', true), true);
    // A click outside that went to a field or a button keeps it there; choosing something changes the page.
    assert.equal(core.flyoutFocusBack('outside', false), false);
    assert.equal(core.flyoutFocusBack('chosen', true), false);
});

test('the places\' memory is one person\'s in one workspace: anyone else\'s goes, and the login page forgets it all', () => {
    const mine = 'aaaaaaaaaaaa.11111111';
    assert.equal(core.memoryKey(mine, 'last', 'library'), 'el-nav:aaaaaaaaaaaa.11111111:last:library');
    assert.equal(core.memoryKey(mine, 'panel', 'projects'), 'el-nav:aaaaaaaaaaaa.11111111:panel:projects');
    const keys = [
        'el-nav:aaaaaaaaaaaa.11111111:last:library', // mine, here
        'el-nav:aaaaaaaaaaaa.22222222:last:library', // mine, in my other workspace
        'el-nav:bbbbbbbbbbbb.11111111:last:library', // someone else's, in this workspace
        'el-nav:bbbbbbbbbbbb.33333333:panel:ask', // someone else's
        'el-nav-last:library', 'el-nav-panel:library', // kept before the memory had a scope
        'filament:theme:mode', 'el-lib-filters', // not the places' memory
    ];
    assert.deepEqual(core.staleMemory(keys, mine), ['el-nav:bbbbbbbbbbbb.11111111:last:library', 'el-nav:bbbbbbbbbbbb.33333333:panel:ask', 'el-nav-last:library', 'el-nav-panel:library']);
    assert.deepEqual(core.allMemory(keys), keys.slice(0, 6));
});

test('Alt+digit in a field does nothing: no panel item opens, whichever field or editor has the focus', () => {
    for (const field of [{ tagName: 'INPUT' }, { tagName: 'input' }, { tagName: 'TEXTAREA' }, { tagName: 'SELECT' }, { field: true }, { editable: true }, { tagName: 'DIV', isContentEditable: true }]) {
        for (let n = 1; n <= 9; n++) {
            assert.equal(core.frameKey(key(String(n), { altKey: true, code: 'Digit' + n, target: field }), frame()), null, `${JSON.stringify(field)} Alt+${n}`);
            assert.equal(core.frameKey(key(String(n), { altKey: true, code: 'Numpad' + n, target: field }), frame()), null, `${JSON.stringify(field)} Alt+Numpad${n}`);
        }
    }
    // Out of a field the same keys open the panel's items.
    assert.deepEqual(core.frameKey(key('1', { altKey: true, code: 'Digit1' }), frame()), { do: 'item', at: 0 });
});

test('each place\'s panel stays as it was left there: open unless folded, never on Home', () => {
    assert.equal(core.panelOpen(null, true), true);
    assert.equal(core.panelOpen('open', true), true);
    assert.equal(core.panelOpen('closed', true), false);
    assert.equal(core.panelOpen(null, false), false);
});

test('⌘1…⌘6 open the rail\'s places (Ctrl off a Mac), and only those there are', () => {
    assert.deepEqual(core.frameKey(key('1', { metaKey: true, code: 'Digit1' }), frame()), { do: 'rail', at: 0 });
    assert.deepEqual(core.frameKey(key('6', { metaKey: true, code: 'Digit6' }), frame()), { do: 'rail', at: 5 });
    assert.deepEqual(core.frameKey(key('3', { ctrlKey: true, code: 'Digit3' }), frame({ mac: false })), { do: 'rail', at: 2 });
    // Seven and up are not the rail's; a rail with fewer places answers fewer.
    assert.equal(core.frameKey(key('7', { metaKey: true, code: 'Digit7' }), frame()), null);
    assert.equal(core.frameKey(key('5', { metaKey: true, code: 'Digit5' }), frame({ rail: 4 })), null);
    // The other platform's modifier is the browser's.
    assert.equal(core.frameKey(key('1', { ctrlKey: true, code: 'Digit1' }), frame()), null);
    assert.equal(core.frameKey(key('1', { metaKey: true, code: 'Digit1' }), frame({ mac: false })), null);
});

test('Alt+1…9 open the panel\'s first nine (saved views keep these), never in a field, where Alt types letters', () => {
    assert.deepEqual(core.frameKey(key('¡', { altKey: true, code: 'Digit1' }), frame()), { do: 'item', at: 0 });
    assert.deepEqual(core.frameKey(key('ª', { altKey: true, code: 'Digit9' }), frame()), { do: 'item', at: 8 });
    assert.equal(core.frameKey(key('™', { altKey: true, code: 'Digit2' }), frame({ items: 1 })), null);
    for (const field of [{ tagName: 'INPUT' }, { tagName: 'TEXTAREA' }, { tagName: 'SELECT' }, { field: true }, { editable: true }]) {
        assert.equal(core.frameKey(key('¡', { altKey: true, code: 'Digit1', target: field }), frame()), null);
    }
});

test('⌘\\ hides or shows the panel, also from a field; nothing over a dialog, with Shift, or when already taken', () => {
    assert.deepEqual(core.frameKey(key('\\', { metaKey: true, code: 'Backslash' }), frame()), { do: 'panel' });
    assert.deepEqual(core.frameKey(key('\\', { ctrlKey: true, code: 'Backslash', target: { tagName: 'INPUT' } }), frame({ mac: false })), { do: 'panel' });
    assert.equal(core.frameKey(key('\\', { metaKey: true, code: 'Backslash' }), frame({ modal: true })), null);
    assert.equal(core.frameKey(key('|', { metaKey: true, shiftKey: true, code: 'Backslash' }), frame()), null);
    assert.equal(core.frameKey(key('\\', { metaKey: true, code: 'Backslash', defaultPrevented: true }), frame()), null);
    // A plain digit or backslash is the page's.
    assert.equal(core.frameKey(key('1', { code: 'Digit1' }), frame()), null);
    assert.equal(core.frameKey(key('\\', { code: 'Backslash' }), frame()), null);
});
