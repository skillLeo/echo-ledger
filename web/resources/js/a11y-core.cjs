// FIX-06 on the web console: what a keyboard and a screen reader need past each control's own markup, as plain functions
// over a document so a test runs them under Node (tests/js/a11y-core.test.mjs). The new console's head
// (resources/views/filament/v2/a11y-script.blade.php) installs them through window.ElA11yCore.
// - A grouped list's day headings are items of the list too (Filament puts them straight in role="list": axe's
//   aria-required-children, critical).
// - A table box that scrolls sideways with nothing to focus in it takes the focus itself, so the keyboard can scroll it
//   (axe's scrollable-region-focusable, serious).
// - The rail is one stop of the Tab key: the arrows (and Home, End) move through it (a roving tabindex).
// (Esc already closes a tooltip: scripts/a11y.mjs checks it.)
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElA11yCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const GROUP_HEADERS = '.fi-ta-content-grouped[role="list"] > .fi-ta-group-header';
    const SCROLLERS = '.fi-ta-content-ctn';
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const RAIL = '.v2-rail';
    const RAIL_ITEMS = 'a[href], button:not([disabled])';

    /** Each day heading of a grouped list becomes one of its items. Answers how many changed. */
    function markGroupHeaders(doc) {
        let changed = 0;
        doc.querySelectorAll(GROUP_HEADERS).forEach((header) => {
            if (header.getAttribute('role') !== 'listitem') {
                header.setAttribute('role', 'listitem');
                changed++;
            }
        });
        return changed;
    }

    /**
     * A table box that scrolls sideways and holds nothing to focus takes the focus (tabindex 0, marked as ours); once it
     * no longer scrolls, or holds something to focus, it gives it back. Answers how many changed.
     */
    function markScrollers(doc) {
        let changed = 0;
        doc.querySelectorAll(SCROLLERS).forEach((box) => {
            const ours = box.getAttribute('data-el-scroll-focus') === '1';
            const needs = box.scrollWidth > box.clientWidth + 1 && box.querySelector(FOCUSABLE) === null;
            if (needs && !ours && !box.hasAttribute('tabindex')) {
                box.setAttribute('tabindex', '0');
                box.setAttribute('data-el-scroll-focus', '1');
                changed++;
            } else if (!needs && ours) {
                box.removeAttribute('tabindex');
                box.removeAttribute('data-el-scroll-focus');
                changed++;
            }
        });
        return changed;
    }

    /**
     * Where an arrow key goes in the rail (a column): Down and Up to the next and the previous (round the ends), Home
     * and End to the first and the last; -1 for any other key.
     */
    function railStep(count, at, key) {
        if (!count) return -1;
        if (key === 'ArrowDown') return at < 0 ? 0 : (at + 1) % count;
        if (key === 'ArrowUp') return at < 0 ? count - 1 : (at - 1 + count) % count;
        if (key === 'Home') return 0;
        if (key === 'End') return count - 1;
        return -1;
    }

    /** The rail's stops, in order: its links and buttons that are shown. */
    function railItems(rail) {
        return Array.from(rail.querySelectorAll(RAIL_ITEMS)).filter((el) => el.getClientRects().length > 0);
    }

    /**
     * One stop of the rail takes Tab (tabindex 0), the others -1: [current], else the place you are on
     * (aria-current), else the first. Answers the stop.
     */
    function rove(items, current) {
        const chosen = items.includes(current) ? current : (items.find((el) => el.getAttribute('aria-current') === 'page') || items[0] || null);
        items.forEach((el) => {
            const want = el === chosen ? '0' : '-1';
            if (el.getAttribute('tabindex') !== want) el.setAttribute('tabindex', want);
        });
        return chosen;
    }

    /** Once per document: all of the above, again after each change Livewire or Alpine makes and as the window changes. */
    function install(doc, win) {
        if (doc.elA11yCore) return false;
        doc.elA11yCore = true;
        let last = null;
        let queued = false;
        const apply = () => {
            queued = false;
            markGroupHeaders(doc);
            markScrollers(doc);
            const rail = doc.querySelector(RAIL);
            if (rail) last = rove(railItems(rail), last);
        };
        const later = () => {
            if (queued) return;
            queued = true;
            (win.requestAnimationFrame || ((f) => win.setTimeout(f, 16)))(apply);
        };
        // On the way down, before a rail button's own menu (Record's, the avatar's) takes the arrows to open: in the rail
        // they move; Enter and Space still open the menu, whose items keep their own keys.
        doc.addEventListener('keydown', (e) => {
            const rail = e.target && typeof e.target.closest === 'function' ? e.target.closest(RAIL) : null;
            if (!rail || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
            if (e.target.closest('[role="menu"], .fi-dropdown-panel')) return;
            const items = railItems(rail);
            const to = railStep(items.length, items.indexOf(e.target), e.key);
            if (to < 0) return;
            e.preventDefault();
            e.stopPropagation();
            last = rove(items, items[to]);
            items[to].focus();
        }, true);
        doc.addEventListener('focusin', (e) => {
            const rail = e.target && typeof e.target.closest === 'function' ? e.target.closest(RAIL) : null;
            if (rail) last = rove(railItems(rail), e.target);
        });
        if (typeof win.MutationObserver === 'function') {
            new win.MutationObserver(later).observe(doc.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['role', 'tabindex'] });
        }
        win.addEventListener('resize', later);
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', apply);
        else apply();
        return true;
    }

    return { GROUP_HEADERS, SCROLLERS, markGroupHeaders, markScrollers, railStep, railItems, rove, install };
});
