// FIX-06 on the web console: what a keyboard and a screen reader need past each control's own markup, as plain functions
// over a document so a test runs them under Node (tests/js/a11y-core.test.mjs). The new console's head
// (resources/views/filament/v2/a11y-script.blade.php) installs them through window.ElA11yCore.
// - A grouped list's day headings are items of the list too (Filament puts them straight in role="list": axe's
//   aria-required-children, critical).
// - A table box that scrolls sideways with nothing to focus in it takes the focus itself, so the keyboard can scroll it
//   (axe's scrollable-region-focusable, serious); while it has the focus it is a region named by the table's heading.
// - The rail is one stop of the Tab key: the arrows (and Home, End) move through it (a roving tabindex). The frame's
//   Blade (filament.v2.frame) writes the same stop before any script runs; this keeps it after each redraw. The rail's
//   menus (Record's, Account's) keep their own keys and focus.
// - A menu with a block that says what it is about (the account menu's header) is described by it.
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
    // Where a table box finds its heading: [around it, the heading in there].
    const TABLE_HEADINGS = [['.fi-ta', '.fi-ta-header-heading'], ['.fi-section', '.fi-section-header-heading']];

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

    /** The words of a table box's heading: its table's, else its section's; '' when it has none. */
    function headingOf(box) {
        if (typeof box.closest !== 'function') return '';
        for (const [around, heading] of TABLE_HEADINGS) {
            const found = box.closest(around)?.querySelector(heading);
            const words = (found?.textContent ?? '').replace(/\s+/g, ' ').trim();
            if (words !== '') return words;
        }
        return '';
    }

    /**
     * A table box that scrolls sideways and holds nothing to focus takes the focus (tabindex 0, marked as ours), as a
     * region named by the table's heading so a screen reader says where it is; once it no longer scrolls, or holds
     * something to focus, it gives all three back. Answers how many changed.
     */
    function markScrollers(doc) {
        let changed = 0;
        doc.querySelectorAll(SCROLLERS).forEach((box) => {
            const ours = box.getAttribute('data-el-scroll-focus') === '1';
            const needs = box.scrollWidth > box.clientWidth + 1 && box.querySelector(FOCUSABLE) === null;
            if (needs && !ours && !box.hasAttribute('tabindex')) {
                box.setAttribute('tabindex', '0');
                box.setAttribute('data-el-scroll-focus', '1');
                const name = headingOf(box);
                if (name !== '') {
                    box.setAttribute('role', 'region');
                    box.setAttribute('aria-label', name);
                }
                changed++;
            } else if (!needs && ours) {
                box.removeAttribute('tabindex');
                box.removeAttribute('data-el-scroll-focus');
                box.removeAttribute('role');
                box.removeAttribute('aria-label');
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

    /** A rail's menu (Record's, Account's) and what is in it: their own keys and focus, not the rail's stops. */
    const MENU = '[role="menu"], .fi-dropdown-panel';

    /** The rail's stops, in order: its links and buttons that are shown, outside its menus. */
    function railItems(rail) {
        return Array.from(rail.querySelectorAll(RAIL_ITEMS)).filter((el) => el.getClientRects().length > 0
            && ! (typeof el.closest === 'function' && el.closest(MENU)));
    }

    /**
     * A menu with a block that says what it is about (data-menu-description: the account menu's header, who it is) is
     * described by it (aria-describedby on the menu), so a screen reader in the menu hears it. Answers how many changed.
     */
    function describeMenus(doc) {
        let changed = 0;
        doc.querySelectorAll('[data-menu-description][id]').forEach((block) => {
            const menu = block.closest('.fi-dropdown-panel');
            if (menu && menu.getAttribute('aria-describedby') !== block.id) {
                menu.setAttribute('aria-describedby', block.id);
                changed++;
            }
        });
        return changed;
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

    /**
     * Once per document: all of the above, again after each change Livewire or Alpine makes, once Alpine has drawn the
     * frame (it is hidden until then: x-cloak), after each wire:navigate and as the window changes.
     */
    function install(doc, win) {
        if (doc.elA11yCore) return false;
        doc.elA11yCore = true;
        let last = null;
        let queued = false;
        const apply = () => {
            queued = false;
            markGroupHeaders(doc);
            markScrollers(doc);
            describeMenus(doc);
            const rail = doc.querySelector(RAIL);
            if (rail) last = rove(railItems(rail), last);
        };
        const later = () => {
            if (queued) return;
            queued = true;
            (win.requestAnimationFrame || ((f) => win.setTimeout(f, 16)))(apply);
        };
        // On the way down, before a rail button's own menu (Record's, the avatar's) takes the arrows to open: in the rail
        // they move; Enter and Space still open the menu, whose items keep their own keys. A trigger whose menu is open
        // (opened by the mouse, the focus still on it) keeps the arrows too: they go into its menu.
        doc.addEventListener('keydown', (e) => {
            const rail = e.target && typeof e.target.closest === 'function' ? e.target.closest(RAIL) : null;
            if (!rail || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
            if (e.target.closest('[role="menu"], .fi-dropdown-panel')) return;
            if (e.target.getAttribute('aria-expanded') === 'true') return;
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
            if (rail && ! e.target.closest(MENU)) last = rove(railItems(rail), e.target);
        });
        if (typeof win.MutationObserver === 'function') {
            new win.MutationObserver(later).observe(doc.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['role', 'tabindex', 'aria-describedby'] });
        }
        win.addEventListener('resize', later);
        doc.addEventListener('alpine:initialized', later);
        // A new page: the stop is the place you are on now, not the one you left.
        doc.addEventListener('livewire:navigated', () => {
            last = null;
            later();
        });
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', apply);
        else apply();
        return true;
    }

    return { GROUP_HEADERS, SCROLLERS, RAIL, RAIL_ITEMS, MENU, TABLE_HEADINGS, markGroupHeaders, markScrollers, describeMenus, headingOf, railStep, railItems, rove, install };
});
