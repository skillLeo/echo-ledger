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
     * What a key does in the Library. e: {key, ctrlKey, metaKey, altKey, defaultPrevented, target}; s: {modal, items
     * (rows on screen), at (the current row, -1 for none), checked (rows ticked on screen), selected (what the table
     * holds selected, across pages too), allChecked}. Returns {do, at?} or null.
     */
    function keyAction(e, s) {
        if (e.defaultPrevented || e.altKey || isTyping(e.target) || s.modal) return null;
        const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        if ((e.ctrlKey || e.metaKey) && key === 'a') return s.allChecked ? { do: 'none' } : { do: 'select-all' };
        if (e.ctrlKey || e.metaKey) return null;
        if (key === 'j' || key === 'k') {
            if (! s.items) return null;
            const at = s.at < 0 ? 0 : Math.max(0, Math.min(s.items - 1, s.at + (key === 'j' ? 1 : -1)));
            return { do: 'move', at };
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

    return { isTyping, isControl, keyAction, clickAction, contextAction };
});
