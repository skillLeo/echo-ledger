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
