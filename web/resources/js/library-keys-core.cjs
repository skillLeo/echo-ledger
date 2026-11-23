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


    /**
     * Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md section 5): the keys of the filter panel and the search, decided
     * before keyAction. e as keyAction's (with code); s: {modal, panelOpen}. F opens or closes the filters, ⌘F / Ctrl+F
     * goes to the search (also from another field), and Esc closes the panel before it clears a selection. A single key
     * with no modifier (F) answers only while the Library has the focus (s.focused), so speech input and stray keys
     * elsewhere never trigger it (WCAG 2.1.4). The numbers are the frame's since docs/PLAN_NAV_3_LEVELS.md (⌘1…⌘6 the
     * rail, Alt+1…9 the panel: resources/js/frame-core.cjs). Returns {do} or null.
     */
    function shellKey(e, s) {
        if (e.defaultPrevented || s.modal) return null;
        const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        if ((e.ctrlKey || e.metaKey) && ! e.altKey && key === 'f') return { do: 'search' };
        if (e.ctrlKey || e.metaKey || e.altKey || isTyping(e.target)) return key === 'Escape' && s.panelOpen ? { do: 'close-filters' } : null;
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

    /**
     * Back and Forward while the Library is still answering the last step (docs/PLAN_NAV_3_LEVELS.md section 0). Livewire
     * applies a step's answer to whatever address is current when it arrives, so a step taken before it (⌘] right after
     * ⌘[, a mouse's buttons, the browser's) had its address rewritten by the late answer and its own update pushed as a
     * new entry. The gate holds a step that comes while one of the Library's requests is out, and hands it back, the
     * latest one only, once none is: the page then puts that step's address and state back and lets it through.
     * start()/end() around each request of the Library (end() returns the held step to replay, or null); pop(entry,
     * now) for each step: 'hold' or 'pass'; heldSince() is when the first step now held came (the time limit counts from
     * it, never from a later one); release() hands the held step back and forgets the requests (the limit).
     */
    function historyGate() {
        let out = 0;
        let waiting = null;
        let since = null;
        const take = () => {
            const step = waiting;
            waiting = null;
            since = null;
            return step;
        };
        return {
            start() { out += 1; },
            end() {
                out = Math.max(0, out - 1);
                return out > 0 || waiting === null ? null : take();
            },
            pop(entry, now) {
                if (out === 0) return 'pass';
                if (waiting === null) since = typeof now === 'number' ? now : 0;
                waiting = entry;
                return 'hold';
            },
            held() { return waiting; },
            heldSince() { return since; },
            release() {
                out = 0;
                return take();
            },
        };
    }

    /**
     * One request of the Library through the gate: counted out now and back exactly once, however it ends. Livewire calls
     * a commit's respond() on every end (answered, skipped, superseded by a newer one, failed, cancelled), while succeed()
     * and fail() miss some of them (a superseded or skipped message, a network failure), so the count never leaks.
     * hooks: the commit hook's {respond}; replay(step): what to do with a held step handed back (or null).
     */
    function watchRequest(gate, hooks, replay) {
        gate.start();
        let done = false;
        const end = () => {
            if (done) return;
            done = true;
            replay(gate.end());
        };
        hooks.respond(end);
        return end;
    }

    /**
     * The gate with its time limit: a step held goes through when the Library's last request out ends, or ten seconds
     * (limitMs) after the first step held at the latest, even while a request hangs (a server that never answers) and
     * other requests (the poll) keep ending. Only handing a step back clears the limit; an ending that hands nothing
     * back (another request still out) leaves the held step and its limit as they are. timers: {set(fn, ms), clear(id)};
     * replay(step): what the page does with a step handed back. handBack is what each request's ending calls with
     * gate.end() (watchRequest's replay); pop(entry, now) is each step's, as the gate's, starting the limit at the first.
     */
    function historyGuard(gate, timers, replay, limitMs) {
        let limit = null;
        const handBack = (step) => {
            if (! step) return;
            if (limit !== null) {
                timers.clear(limit);
                limit = null;
            }
            replay(step);
        };
        return {
            handBack,
            pop(entry, now) {
                const result = gate.pop(entry, now);
                if (result === 'hold' && limit === null) {
                    limit = timers.set(() => {
                        limit = null;
                        handBack(gate.release());
                    }, typeof limitMs === 'number' ? limitMs : 10000);
                }
                return result;
            },
            limited() { return limit !== null; },
        };
    }

    /**
     * Whether a held step may still go through when it is handed back: not when the person went somewhere since (a filter
     * or a tab pushed a new entry while it was held), or its replay would overwrite that newer entry. held and now:
     * {key (the Navigation API's current entry key, where the browser has it), pushes (how many entries the page pushed)}.
     * The key decides where there is one (a replace keeps it, a push changes it); else no push since the hold.
     */
    function stillCurrent(held, now) {
        if (held && now && typeof held.key === 'string' && typeof now.key === 'string') return held.key === now.key;
        return !! held && !! now && held.pushes === now.pushes;
    }

    return { isTyping, isField, isControl, keyAction, clickAction, contextAction, shellKey, historyKey, historyGate, historyGuard, watchRequest, stillCurrent };
});
