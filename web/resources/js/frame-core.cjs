// The console's three levels of navigation (docs/PLAN_NAV_3_LEVELS.md) as plain decisions with no browser in them, so a
// test runs them under Node (tests/js/frame-core.test.mjs). resources/views/filament/v2/frame-script.blade.php reads
// the page, asks these what to do and does it, through window.ElFrameCore.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElFrameCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const FIELDS = ['input', 'textarea', 'select'];
    function isField(target) {
        return !! target && (FIELDS.includes(String(target.tagName || '').toLowerCase()) || target.field === true || target.editable === true || target.isContentEditable === true);
    }

    /**
     * Where a click on a rail icon goes (section 6, "remember per place"; section 0, "clicking the selected rail icon
     * resets that place"). s: {key (the place clicked), current (the place you are in, null on Home), home (its own
     * page), last (where you left that place, from this browser's memory), origin (this console's)}. On the place you
     * are in: its own page, and the memory goes ({go, reset: true}). On another: where you left it, when that is a page
     * of this console, else its own page ({go}).
     */
    function railTarget(s) {
        if (s.key === s.current) return { go: s.home, reset: true };
        return { go: sameConsole(s.last, s.origin) ? s.last : s.home, reset: false };
    }

    /** Whether a remembered address is a page of this console (never another site, never a script). */
    function sameConsole(address, origin) {
        if (typeof address !== 'string' || address === '' || address[0] !== '/' || address.startsWith('//')) return false;
        try {
            return new URL(address, origin).origin === origin;
        } catch (e) {
            return false;
        }
    }

    /**
     * What the rail does with a click when the panel is folded away (under the flyout width, or on a phone's drawer):
     * another place with a panel opens its panel as a flyout (the same one again closes it), the place you are in starts
     * over as on a computer and its flyout opens or closes too ('restart'), a place without one is followed.
     * s: {narrow, hasPanel, flyout (the place whose panel is open), key, current (the place you are in)}. Returns
     * 'open', 'close', 'restart' or 'go'.
     */
    function railFlyout(s) {
        if (! s.narrow || ! s.hasPanel) return 'go';
        if (s.key === s.current) return 'restart';
        return s.flyout === s.key ? 'close' : 'open';
    }

    /**
     * Whether focus goes back to what opened the flyout when it closes: after Esc and ⊟ (or ⌘\) always; after a click
     * outside it only when that click left focus nowhere a person works with (the body, the page's main, or the flyout
     * now hidden), never taking it from a field or button the click went to; after choosing something in it, never (the
     * page changes). how: 'escape' | 'toggle' | 'outside' | 'chosen'; lost: whether focus is on nothing a person works
     * with, or still in the flyout.
     */
    function flyoutFocusBack(how, lost) {
        if (how === 'escape' || how === 'toggle') return true;
        return how === 'outside' && lost === true;
    }

    /**
     * This browser's memory of each place (where you left it, whether its panel was folded) belongs to one person in one
     * workspace: scope is an opaque name of both (person.workspace, from the server), so it never carries over to
     * someone else signing in here, and the login page forgets it all (signing out). what: 'last' | 'panel'.
     */
    function memoryKey(scope, what, place) {
        return 'el-nav:' + scope + ':' + what + ':' + place;
    }

    /**
     * The remembered keys to forget when scope signs in here: anyone else's (another person; the same person's other
     * workspaces stay) and the unscoped ones kept before memory had a scope. keys: this browser's storage keys.
     */
    function staleMemory(keys, scope) {
        const person = String(scope).split('.')[0];
        return keys.filter((k) => {
            if (typeof k !== 'string') return false;
            if (k.startsWith('el-nav-last:') || k.startsWith('el-nav-panel:')) return true;
            if (! k.startsWith('el-nav:')) return false;
            return k.slice('el-nav:'.length).split(':')[0].split('.')[0] !== person;
        });
    }

    /** Every key of the places' memory, to forget on the login page (after signing out). */
    function allMemory(keys) {
        return keys.filter((k) => typeof k === 'string' && (k.startsWith('el-nav:') || k.startsWith('el-nav-last:') || k.startsWith('el-nav-panel:')));
    }

    /**
     * Whether the panel shows beside the page for a place, from this browser's memory of it ('open', 'closed' or
     * nothing): open unless that place was folded. Home and a place without a panel: never.
     */
    function panelOpen(memory, hasPanel) {
        return hasPanel && memory !== 'closed';
    }

    /**
     * The frame's keys (section 6), on every page. e: {key, code, ctrlKey, metaKey, altKey, shiftKey, defaultPrevented,
     * target}; s: {mac, modal, rail (how many places the rail has, at most 6), items (how many of the panel's first
     * nine answer a number)}. ⌘1…⌘6 (Ctrl off a Mac) open the rail's places, Alt+1…9 the panel's first nine (saved views
     * keep these, so the two never clash), ⌘\ hides or shows the panel. Not over a dialog. No number while a field or
     * an editor has the focus (⌘1 would leave the page and lose what was typed; Alt types letters there on a Mac); ⌘\
     * works from a field too. Returns {do: 'rail' | 'item' | 'panel', at?} or null.
     */
    function frameKey(e, s) {
        if (e.defaultPrevented || s.modal || e.shiftKey) return null;
        const mod = s.mac ? e.metaKey && ! e.ctrlKey : e.ctrlKey && ! e.metaKey;
        const digit = /^Digit([1-9])$/.exec(e.code || '') || /^Numpad([1-9])$/.exec(e.code || '');
        if (mod && ! e.altKey) {
            if (e.code === 'Backslash' || e.key === '\\') return { do: 'panel' };
            if (digit && ! isField(e.target)) {
                const at = Number(digit[1]) - 1;
                return at < Math.min(6, s.rail) ? { do: 'rail', at } : null;
            }
            return null;
        }
        if (e.altKey && ! e.metaKey && ! e.ctrlKey && digit && ! isField(e.target)) {
            const at = Number(digit[1]) - 1;
            return at < Math.min(9, s.items) ? { do: 'item', at } : null;
        }
        return null;
    }

    return { isField, railTarget, sameConsole, railFlyout, flyoutFocusBack, memoryKey, staleMemory, allMemory, panelOpen, frameKey };
});
