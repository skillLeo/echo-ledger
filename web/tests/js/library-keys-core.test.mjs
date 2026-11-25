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

test('The numbers are the frame\'s now (frame-core: ⌘1…⌘6 the rail, Alt+1…9 the panel), never the Library\'s', () => {
    for (const more of [{ metaKey: true }, { ctrlKey: true }, { altKey: true }, {}]) {
        assert.equal(core.shellKey(key('1', { code: 'Digit1', ...more }), shell()), null);
    }
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
    assert.equal(core.historyKey(key('x', { metaKey: true, code: 'KeyX' }), mac), null);
});

test('⌘[ and ⌘] in a form field, an editor or over a dialog keep the page (the browser\'s Back would lose the typing); an editor that uses them claims them first', () => {
    const mac = { mac: true, modal: false };
    // The Save as view name, a search box, a list: the key is taken from the browser and nothing moves.
    for (const field of [{ tagName: 'INPUT' }, { tagName: 'TEXTAREA' }, { tagName: 'SELECT' }, { field: true }]) {
        assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft', target: field }), mac), 'stay');
        assert.equal(core.historyKey(key(']', { metaKey: true, code: 'BracketRight', target: field }), mac), 'stay');
    }
    // A dialog is open: the same, wherever the focus is.
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft' }), { mac: true, modal: true }), 'stay');
    assert.equal(core.historyKey(key('[', { ctrlKey: true, code: 'BracketLeft', target: { tagName: 'INPUT' } }), { mac: false, modal: false }), 'stay');
    // An editor of rich text that uses ⌘[ / ⌘] (outdent, indent) claims the key before the page asks: nothing more.
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft', defaultPrevented: true, target: { editable: true } }), mac), null);
    assert.equal(core.historyKey(key(']', { metaKey: true, code: 'BracketRight', defaultPrevented: true, target: { isContentEditable: true } }), { mac: true, modal: true }), null);
    // One that did not claim it keeps the page and the typing too (the browser's Back would leave it).
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft', target: { editable: true } }), mac), 'stay');
    assert.equal(core.historyKey(key(']', { metaKey: true, code: 'BracketRight', target: { isContentEditable: true } }), mac), 'stay');
    // Claimed elsewhere on the page (another handler): nothing more either.
    assert.equal(core.historyKey(key('[', { metaKey: true, code: 'BracketLeft', defaultPrevented: true }), mac), null);
    // Other keys in a field stay the field's.
    assert.equal(core.historyKey(key('a', { metaKey: true, code: 'KeyA', target: { tagName: 'INPUT' } }), mac), null);
    assert.equal(core.historyKey(key('[', { code: 'BracketLeft', target: { tagName: 'INPUT' } }), mac), null);
});

// The desktop's D-220 and D-221 on the web: ↓ ↑ walk the list as J K do; the keys answer on an Urdu keyboard layout.
test('↓ and ↑ on a row go from row to row as J and K do, the focus along, never past the ends', () => {
    const row = { control: true };
    assert.deepEqual(core.keyAction(key('ArrowDown', { target: row, onRow: true }), list({ focusAt: 1 })), { do: 'move', at: 2, focus: true });
    assert.deepEqual(core.keyAction(key('ArrowUp', { target: row, onRow: true }), list({ focusAt: 1, at: 4 })), { do: 'move', at: 0, focus: true },
        'from the row that has the focus, not the one J and K left');
    assert.deepEqual(core.keyAction(key('ArrowDown', { target: row, onRow: true }), list({ focusAt: 4 })), { do: 'move', at: 4, focus: true });
    assert.deepEqual(core.keyAction(key('ArrowUp', { target: row, onRow: true }), list({ focusAt: 0 })), { do: 'move', at: 0, focus: true });
    // J and K from a focused row: from that row, the focus along.
    assert.deepEqual(core.keyAction(key('j', { target: row, onRow: true }), list({ focusAt: 2 })), { do: 'move', at: 3, focus: true });
});

test('↓ and ↑ on the page move the current row once there is one; else, and anywhere else, they are the page\'s', () => {
    assert.deepEqual(core.keyAction(key('ArrowDown', { onPage: true }), list({ at: 2 })), { do: 'move', at: 3 });
    assert.equal(core.keyAction(key('ArrowDown', { onPage: true }), list()), null, 'no current row: the page scrolls');
    assert.equal(core.keyAction(key('ArrowDown'), list({ at: 2 })), null, 'on something else that has the focus (a box that scrolls)');
    assert.equal(core.keyAction(key('ArrowDown', { target: { control: true } }), list({ at: 2 })), null, 'a menu\'s button keeps its arrows');
    assert.equal(core.keyAction(key('ArrowDown', { target: { tagName: 'INPUT' }, onRow: true }), list({ focusAt: 1 })), null);
    assert.equal(core.keyAction(key('ArrowDown', { onRow: true, shiftKey: true }), list({ focusAt: 1 })), null, 'Shift: the page\'s own');
    assert.equal(core.keyAction(key('ArrowDown', { onRow: true, altKey: true }), list({ focusAt: 1 })), null);
    assert.equal(core.keyAction(key('ArrowDown', { onRow: true }), list({ focusAt: 1, items: 0 })), null);
});

test('latinKey: an Urdu letter, digit or mark stands for the Latin key at its place; a Latin layout keeps its own', () => {
    assert.equal(core.latinKey({ key: 'ج', code: 'KeyJ' }), 'j');
    assert.equal(core.latinKey({ key: 'ک', code: 'KeyK' }), 'k');
    assert.equal(core.latinKey({ key: 'ف', code: 'KeyF', shiftKey: true }), 'f');
    assert.equal(core.latinKey({ key: '۱', code: 'Digit1' }), '1');
    assert.equal(core.latinKey({ key: 'ظ', code: 'Slash' }), '/');
    assert.equal(core.latinKey({ key: '؟', code: 'Slash', shiftKey: true }), '?');
    assert.equal(core.latinKey({ key: 'ج', code: 'BracketLeft' }), '[');
    assert.equal(core.latinKey({ key: 'a', code: 'KeyQ' }), 'a', 'AZERTY\'s A is A');
    assert.equal(core.latinKey({ key: '/', code: 'Digit7', shiftKey: true }), '/');
    assert.equal(core.latinKey({ key: 'ArrowDown', code: 'ArrowDown' }), 'ArrowDown');
    assert.equal(core.latinKey({ key: 'ے', code: 'IntlRo' }), 'ے', 'a place with no Latin key stays as typed');
    assert.equal(core.latinKey({ key: 'ج' }), 'ج', 'no code (a synthetic event): as typed');
});

test('on an Urdu keyboard layout J, K, X, Ctrl+A, F and Ctrl+F answer by their key\'s place', () => {
    const ur = (k, code, more = {}) => key(k, { code, ...more });
    assert.deepEqual(core.keyAction(ur('ج', 'KeyJ'), list({ at: 1 })), { do: 'move', at: 2 });
    assert.deepEqual(core.keyAction(ur('ک', 'KeyK'), list({ at: 1 })), { do: 'move', at: 0 });
    assert.deepEqual(core.keyAction(ur('ش', 'KeyX'), list({ at: 3 })), { do: 'toggle', at: 3 });
    assert.deepEqual(core.keyAction(ur('ا', 'KeyA', { ctrlKey: true }), list()), { do: 'select-all' });
    assert.deepEqual(core.shellKey(ur('ف', 'KeyF'), shell()), { do: 'toggle-filters' });
    assert.deepEqual(core.shellKey(ur('ف', 'KeyF', { metaKey: true }), shell()), { do: 'search' });
    // ⌥ on a Mac types another character: X with Alt is never the Library's.
    assert.equal(core.keyAction(ur('≈', 'KeyX', { altKey: true }), list({ at: 3 })), null);
    // Still nothing while typing: an Urdu word in the search is words, not keys.
    assert.equal(core.keyAction(ur('ج', 'KeyJ', { target: field }), list({ at: 1 })), null);
});

test('/ and ? on an Urdu layout reach the search and Help, as / and ? do on a Latin one (left to the console\'s own)', () => {
    assert.deepEqual(core.shellKey(key('ظ', { code: 'Slash' }), shell()), { do: 'search' });
    assert.deepEqual(core.shellKey(key('؟', { code: 'Slash', shiftKey: true }), shell()), { do: 'help' });
    assert.equal(core.shellKey(key('/', { code: 'Slash' }), shell()), null, 'a Latin / is the console\'s (filament.shell.head)');
    assert.equal(core.shellKey(key('?', { code: 'Slash', shiftKey: true }), shell()), null);
    assert.equal(core.shellKey(key('ظ', { code: 'Slash', target: field }), shell()), null, 'typed in a field');
    assert.equal(core.shellKey(key('ظ', { code: 'Slash', ctrlKey: true }), shell()), null);
});
