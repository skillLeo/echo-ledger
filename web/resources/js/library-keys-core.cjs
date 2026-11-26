// The v2.0 Library's keys and mouse (docs/PLAN_V2_PARITY.md section 3) as plain decisions with no browser in them, so a
// test runs them under Node (tests/js/library-keys-core.test.mjs). resources/views/filament/v2/library-keys.blade.php
// reads the page, asks these what to do and does it, through window.ElLibraryKeysCore.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElLibraryKeysCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** The punctuation keys by their place on the keyboard, unshifted and with Shift (latinKey). */
    const PLACES = {
        Slash: ['/', '?'], BracketLeft: ['[', '{'], BracketRight: [']', '}'], Backslash: ['\\', '|'], Period: ['.', '>'],
        Comma: [',', '<'], Minus: ['-', '_'], Equal: ['=', '+'], Semicolon: [';', ':'], Quote: ["'", '"'], Backquote: ['`', '~'],
    };

    /**
     * The key pressed, as a Latin keyboard names it (the desktop's D-221). With an Urdu layout (or Arabic, Persian,
     * Russian…) a letter's key types that layout's letter, so J, K, X, F and / would never answer: then the key's place
     * (e.code: KeyJ, Digit1, Slash) stands for it, a letter in lower case. A Latin layout keeps its own letters (AZERTY's
     * A is A), and a key that names itself (Enter, ArrowDown) or an event with no code stays as it is.
     */
    function latinKey(e) {
        const key = String(e.key || '');
        const code = e.code;
        if (! code || [...key].length !== 1 || /^[\x20-\x7e]$/.test(key)) return key;
        const letter = /^Key([A-Z])$/.exec(code);
        if (letter) return letter[1].toLowerCase();
        const digit = /^(?:Digit|Numpad)([0-9])$/.exec(code);
        if (digit) return digit[1];
        const place = PLACES[code];
        return place ? place[e.shiftKey ? 1 : 0] : key;
    }

    /** A key's name for the decisions below: latinKey, a single character in lower case. */
    function keyOf(e) {
        const key = latinKey(e);
        return key.length === 1 ? key.toLowerCase() : key;
    }

    /** Whether a key or click lands where the person types: a text field, a list, an editable part. */
    const TYPING = ['input', 'textarea', 'select'];
    function isTyping(target) {
        return !! target && (TYPING.includes(String(target.tagName || '').toLowerCase()) || target.isContentEditable === true || target.typing === true);
    }

    /** Whether a key lands on a button or a link of its own (its Enter or X is that control's, not the Library's). */
    const CONTROLS = ['button', 'a', 'summary'];
    function isControl(target) {
        return !! target && (CONTROLS.includes(String(target.tagName || '').toLowerCase()) || target.control === true);
    }

    /**
     * What a key does in the Library. e: {key, code, shiftKey, ctrlKey, metaKey, altKey, defaultPrevented, target, onRow
     * (the focus is on a row's own link), onPage (on the page itself: nothing else has the focus)}; s: {modal, items
     * (rows on screen), at (the current row, -1 for none), focusAt (the row that has the focus, -1 for none), checked
     * (rows ticked on screen), selected (what the table holds selected, across pages too), allChecked}. The keys follow
     * the key's place on a non-Latin layout (latinKey). Returns {do, at?, focus?} or null.
     */
    function keyAction(e, s) {
        if (e.defaultPrevented || e.altKey || isTyping(e.target) || s.modal) return null;
        const key = keyOf(e);
        if ((e.ctrlKey || e.metaKey) && key === 'a') return s.allChecked ? { do: 'none' } : { do: 'select-all' };
        if (e.ctrlKey || e.metaKey) return null;
        // J and K move through the list; so do ↓ and ↑ (the desktop's D-220) on a row, or on the page once a row is
        // the current one, never past the ends. From a focused row the focus goes along.
        const arrow = (key === 'ArrowDown' || key === 'ArrowUp') && ! e.shiftKey && (e.onRow === true || (e.onPage === true && s.at >= 0));
        if (key === 'j' || key === 'k' || arrow) {
            if (! s.items) return null;
            const onRow = e.onRow === true && s.focusAt >= 0;
            const from = onRow ? s.focusAt : s.at;
            const down = key === 'j' || key === 'ArrowDown';
            const at = from < 0 ? 0 : Math.max(0, Math.min(s.items - 1, from + (down ? 1 : -1)));
            return onRow ? { do: 'move', at, focus: true } : { do: 'move', at };
        }
        if ((key === 'Enter' || key === 'x') && isControl(e.target)) return null;
        if (key === 'Enter' && s.at >= 0) return { do: 'open', at: s.at };
        if (key === 'x' && s.at >= 0) return { do: 'toggle', at: s.at };
        if (key === 'Escape' && s.checked > 0) return { do: 'clear' };
        // Delete takes only what is on screen: a selection that reaches past this page is left to the bar's own button.
        if ((key === 'Delete' || key === 'Backspace') && s.checked > 0 && s.selected === s.checked) return { do: 'delete' };
        return null;
    }

    /**
     * What a left click on the list does. e: {button, shiftKey, target}; s: {inRow, onControl (a button, link icon or
     * box of its own), checked}. While something is selected a click on a row adds or takes it away; Shift takes the range.
     */
    function clickAction(e, s) {
        if (! s.inRow || e.button !== 0 || s.onControl || isTyping(e.target)) return null;
        if (e.shiftKey) return 'range';
        return s.checked > 0 ? 'toggle' : null;
    }

    /** What a right-click on the list opens: ⋮ of the selection when the row is part of it, else the row's own menu. */
    function contextAction(e, s) {
        if (! s.inRow || isTyping(e.target)) return null;
        return s.rowChecked && s.checked > 1 ? 'selection-menu' : 'row-menu';
    }


    /**
     * Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md section 5): the keys of the filter panel and the search, decided
     * before keyAction. e as keyAction's (with code); s: {modal, panelOpen}. F opens or closes the filters, ⌘F / Ctrl+F
     * goes to the search (also from another field), and Esc closes the panel before it clears a selection. On a
     * non-Latin layout (latinKey) / goes to the search and ? opens Help, as the console's own / and ? do on a Latin one. A single key
     * with no modifier (F) answers only while the Library has the focus (s.focused), so speech input and stray keys
     * elsewhere never trigger it (WCAG 2.1.4). The numbers are the frame's since docs/PLAN_NAV_3_LEVELS.md (⌘1…⌘6 the
     * rail, Alt+1…9 the panel: resources/js/frame-core.cjs). Returns {do} or null.
     */
    function shellKey(e, s) {
        if (e.defaultPrevented || s.modal) return null;
        const key = keyOf(e);
        if ((e.ctrlKey || e.metaKey) && ! e.altKey && key === 'f') return { do: 'search' };
        if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) return key === 'Escape' && s.panelOpen ? { do: 'close-filters' } : null;
        // / and ? on a non-Latin layout (their key's place, D-221): the console's own / and ? answer the Latin keys.
        if (key === '/' && e.key !== '/') return { do: 'search' };
        if (key === '?' && e.key !== '?') return { do: 'help' };
        if (key === 'f') return s.focused ? { do: 'toggle-filters' } : null;
        if (key === 'Escape' && s.panelOpen) return { do: 'close-filters' };
        return null;
    }

    /** Whether a key lands in a form field (an input, a text area or a list), not an editor of rich text. */
    const FIELDS = ['input', 'textarea', 'select'];
    function isField(target) {
        return !! target && (FIELDS.includes(String(target.tagName || '').toLowerCase()) || target.field === true);
    }

    /**
     * Back and Forward from the keyboard (docs/PLAN_NAV_3_LEVELS.md sections 0 and 6): ⌘[ and ⌘] on a Mac, Ctrl+[ and
     * Ctrl+] elsewhere, as the apps have them; the browser's own Back, Forward and the mouse's back button keep working
     * (the Library keeps its place, filters and view in the address). On a Mac ⌘[ and ⌘] are the browser's Back and
     * Forward too. The page asks after the focused element has had the key (it listens as the key bubbles up): an
     * editor of rich text that uses ⌘[ / ⌘] (outdent, indent) claims it first (defaultPrevented: null, nothing more);
     * in any field left over (an input, a text area, a list, an editor that did not claim it) and over a dialog the
     * key does nothing ('stay': the page and the typing stay). e as keyAction's (with code and shiftKey;
     * target.editable for contenteditable); s: {mac, modal}. Returns 'back', 'forward', 'stay' or null.
     */
    function historyKey(e, s) {
        if (e.altKey || e.shiftKey) return null;
        if (s.mac ? ! (e.metaKey && ! e.ctrlKey) : ! (e.ctrlKey && ! e.metaKey)) return null;
        const way = e.code === 'BracketLeft' || e.key === '[' ? 'back' : (e.code === 'BracketRight' || e.key === ']' ? 'forward' : null);
        if (! way || e.defaultPrevented) return null;
        const editor = !! e.target && (e.target.editable === true || e.target.isContentEditable === true);
        return s.modal || editor || isField(e.target) ? 'stay' : way;
    }

    return { latinKey, isTyping, isField, isControl, keyAction, clickAction, contextAction, shellKey, historyKey };
});
