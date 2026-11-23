// The v2.0 Library's keys and mouse (resources/js/library-keys-core.cjs), without a browser:
// node --test tests/js/library-keys-core.test.mjs (Pest runs it too: tests/Feature/V2/LibrarySelectionTest.php).
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/library-keys-core.cjs');
const page = { tagName: 'BODY' };
const key = (k, more = {}) => ({ key: k, ctrlKey: false, metaKey: false, altKey: false, defaultPrevented: false, target: page, ...more });
const list = (more = {}) => ({ modal: false, items: 5, at: -1, checked: 0, selected: 0, allChecked: false, ...more });

test('Ctrl+A and Cmd+A select every recording on the page, once', () => {
    assert.deepEqual(core.keyAction(key('a', { ctrlKey: true }), list()), { do: 'select-all' });
    assert.deepEqual(core.keyAction(key('A', { metaKey: true }), list()), { do: 'select-all' });
    assert.deepEqual(core.keyAction(key('a', { metaKey: true }), list({ allChecked: true })), { do: 'none' });
    // A plain A, and other shortcuts with Ctrl or Cmd, are not the Library's.
    assert.equal(core.keyAction(key('a'), list()), null);
    assert.equal(core.keyAction(key('c', { ctrlKey: true }), list({ checked: 2, selected: 2 })), null);
});

test('Delete and Backspace delete what is ticked on screen, and only that', () => {
    assert.deepEqual(core.keyAction(key('Delete'), list({ checked: 2, selected: 2 })), { do: 'delete' });
    assert.deepEqual(core.keyAction(key('Backspace'), list({ checked: 1, selected: 1 })), { do: 'delete' });
    assert.equal(core.keyAction(key('Delete'), list()), null);
    // After "Select all 120" the selection reaches past this page: the key leaves it to the bar's own button.
    assert.equal(core.keyAction(key('Delete'), list({ checked: 25, selected: 120 })), null);
    assert.equal(core.keyAction(key('Delete'), list({ checked: 2, selected: 2, modal: true })), null);
});

test('J, K, Enter, X and Esc move, open, tick and clear', () => {
    assert.deepEqual(core.keyAction(key('j'), list()), { do: 'move', at: 0 });
    assert.deepEqual(core.keyAction(key('j'), list({ at: 4 })), { do: 'move', at: 4 });
    assert.deepEqual(core.keyAction(key('K'), list({ at: 2 })), { do: 'move', at: 1 });
    assert.equal(core.keyAction(key('j'), list({ items: 0 })), null);
    assert.deepEqual(core.keyAction(key('Enter'), list({ at: 3 })), { do: 'open', at: 3 });
    assert.equal(core.keyAction(key('Enter'), list()), null);
    assert.deepEqual(core.keyAction(key('x'), list({ at: 1 })), { do: 'toggle', at: 1 });
    assert.deepEqual(core.keyAction(key('Escape'), list({ checked: 3, selected: 3 })), { do: 'clear' });
    assert.equal(core.keyAction(key('Escape'), list()), null);
});

test('Enter and X on a button or a link of its own are that control\'s, not the Library\'s', () => {
    for (const target of [{ tagName: 'BUTTON' }, { tagName: 'a' }, { tagName: 'SUMMARY' }, { control: true }]) {
        assert.equal(core.keyAction(key('Enter', { target }), list({ at: 2 })), null);
        assert.equal(core.keyAction(key('x', { target }), list({ at: 2 })), null);
    }
    // J and K still move from a focused button; Enter on the page opens the current row.
    assert.deepEqual(core.keyAction(key('j', { target: { tagName: 'BUTTON' } }), list({ at: 0 })), { do: 'move', at: 1 });
    assert.deepEqual(core.keyAction(key('Enter'), list({ at: 2 })), { do: 'open', at: 2 });
});

test('no key fires while the person types in a field, an editable part or a dialog', () => {
    for (const target of [{ tagName: 'INPUT' }, { tagName: 'textarea' }, { tagName: 'SELECT' }, { tagName: 'DIV', isContentEditable: true }, { typing: true }]) {
        for (const k of [key('a', { ctrlKey: true, target }), key('a', { metaKey: true, target }), key('Delete', { target }), key('Backspace', { target }), key('j', { target }), key('Escape', { target })]) {
            assert.equal(core.keyAction(k, list({ checked: 2, selected: 2, at: 1 })), null, `${k.key} in ${target.tagName || 'a field'}`);
        }
        assert.equal(core.clickAction({ button: 0, shiftKey: true, target }, { inRow: true, onControl: false, checked: 2 }), null);
        assert.equal(core.contextAction({ target }, { inRow: true, rowChecked: true, checked: 2 }), null);
    }
    assert.equal(core.keyAction(key('Delete', { altKey: true }), list({ checked: 1, selected: 1 })), null);
    assert.equal(core.keyAction(key('Delete', { defaultPrevented: true }), list({ checked: 1, selected: 1 })), null);
});

test('Shift-click takes the range; a click adds or takes away only while something is selected', () => {
    const row = (more = {}) => ({ inRow: true, onControl: false, checked: 0, ...more });
    assert.equal(core.clickAction({ button: 0, shiftKey: true, target: page }, row()), 'range');
    assert.equal(core.clickAction({ button: 0, shiftKey: true, target: page }, row({ checked: 1 })), 'range');
    assert.equal(core.clickAction({ button: 0, shiftKey: false, target: page }, row({ checked: 1 })), 'toggle');
    // Nothing selected: a click opens the recording as before.
    assert.equal(core.clickAction({ button: 0, shiftKey: false, target: page }, row()), null);
    // The row's own buttons and boxes keep their click; so do other buttons and the space outside the list.
    assert.equal(core.clickAction({ button: 0, shiftKey: true, target: page }, row({ onControl: true, checked: 1 })), null);
    assert.equal(core.clickAction({ button: 1, shiftKey: false, target: page }, row({ checked: 1 })), null);
    assert.equal(core.clickAction({ button: 0, shiftKey: true, target: page }, row({ inRow: false })), null);
});

test('right-click opens ⋮ of the selection on a selected row, else the row\'s own menu', () => {
    assert.equal(core.contextAction({ target: page }, { inRow: true, rowChecked: true, checked: 3 }), 'selection-menu');
    assert.equal(core.contextAction({ target: page }, { inRow: true, rowChecked: false, checked: 3 }), 'row-menu');
    // One ticked row is its own menu.
    assert.equal(core.contextAction({ target: page }, { inRow: true, rowChecked: true, checked: 1 }), 'row-menu');
    assert.equal(core.contextAction({ target: page }, { inRow: false, rowChecked: false, checked: 0 }), null);
});

// Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md section 5): F, ⌘F / Ctrl+F, Esc and ⌘1…⌘9.
const shell = (more = {}) => ({ modal: false, panelOpen: false, places: 7, focused: true, ...more });
const field = { tagName: 'INPUT' };

test('F opens and closes the filter panel, but never while typing or with a modifier', () => {
    assert.deepEqual(core.shellKey(key('f'), shell()), { do: 'toggle-filters' });
    // A single key answers only while the Library has the focus (WCAG 2.1.4).
    assert.equal(core.shellKey(key('f'), shell({ focused: false })), null);
    assert.deepEqual(core.shellKey(key('F'), shell({ panelOpen: true })), { do: 'toggle-filters' });
    assert.equal(core.shellKey(key('f', { target: field }), shell()), null);
    assert.equal(core.shellKey(key('f', { altKey: true }), shell()), null);
    assert.equal(core.shellKey(key('f'), shell({ modal: true })), null);
});

test('Cmd+F and Ctrl+F go to the Library search, from another field too', () => {
    assert.deepEqual(core.shellKey(key('f', { metaKey: true }), shell()), { do: 'search' });
    assert.deepEqual(core.shellKey(key('F', { ctrlKey: true, target: field }), shell()), { do: 'search' });
    assert.equal(core.shellKey(key('f', { ctrlKey: true, defaultPrevented: true }), shell()), null);
});

test('Esc closes the panel first; with it closed, Esc is the selection\'s', () => {
    assert.deepEqual(core.shellKey(key('Escape'), shell({ panelOpen: true })), { do: 'close-filters' });
    assert.deepEqual(core.shellKey(key('Escape', { target: field }), shell({ panelOpen: true })), { do: 'close-filters' });
    assert.equal(core.shellKey(key('Escape'), shell()), null);
    assert.deepEqual(core.keyAction(key('Escape'), list({ checked: 2, selected: 2 })), { do: 'clear' });
});

test('Cmd, Ctrl or Alt with 1 to 9 opens the sidebar\'s first nine places, and only those there are', () => {
    assert.deepEqual(core.shellKey(key('1', { metaKey: true, code: 'Digit1' }), shell()), { do: 'place', at: 0 });
    assert.deepEqual(core.shellKey(key('¡', { altKey: true, code: 'Digit1' }), shell()), { do: 'place', at: 0 });
    assert.deepEqual(core.shellKey(key('7', { ctrlKey: true, code: 'Digit7' }), shell()), { do: 'place', at: 6 });
    assert.equal(core.shellKey(key('8', { ctrlKey: true, code: 'Digit8' }), shell()), null);
    assert.equal(core.shellKey(key('0', { metaKey: true, code: 'Digit0' }), shell()), null);
    assert.equal(core.shellKey(key('2', { metaKey: true, code: 'Digit2', target: field }), shell()), null);
    // A plain digit is the page's.
    assert.equal(core.shellKey(key('2', { code: 'Digit2' }), shell()), null);
});

test('⌘[ and ⌘] go Back and Forward on a Mac, Ctrl+[ and Ctrl+] elsewhere, never while typing or over a dialog', () => {
    const mac = { mac: true, modal: false };
    const pc = { mac: false, modal: false };
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft' }), mac), 'back');
    assert.equal(core.historyKey(key(']', { metaKey: true, code: 'BracketRight' }), mac), 'forward');
    assert.equal(core.historyKey(key('[', { ctrlKey: true, code: 'BracketLeft' }), pc), 'back');
    assert.equal(core.historyKey(key(']', { ctrlKey: true, code: 'BracketRight' }), pc), 'forward');
    // A layout whose bracket key types another character still goes by the key's place.
    assert.equal(core.historyKey(key('ü', { metaKey: true, code: 'BracketLeft' }), mac), 'back');
    // The other platform's modifier, Alt or Shift with it, no modifier, typing and a dialog: nothing.
    assert.equal(core.historyKey(key('[', { ctrlKey: true, code: 'BracketLeft' }), mac), null);
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft' }), pc), null);
    assert.equal(core.historyKey(key('[', { metaKey: true, altKey: true, code: 'BracketLeft' }), mac), null);
    assert.equal(core.historyKey(key('{', { metaKey: true, shiftKey: true, code: 'BracketLeft' }), mac), null);
    assert.equal(core.historyKey(key('[', { code: 'BracketLeft' }), mac), null);
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft', target: { typing: true } }), mac), null);
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft' }), { mac: true, modal: true }), null);
    assert.equal(core.historyKey(key('x', { metaKey: true, code: 'KeyX' }), mac), null);
});
