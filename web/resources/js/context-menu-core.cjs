// UX-22 (PLAN_V2_1_POLISH 3b) on the web console: what a right-click menu holds and how its keys move, as plain
// decisions with no browser in them, so a test runs them under Node (tests/js/context-menu-core.test.mjs). The words,
// icons and kinds come from the one action list (resources/js/actions.json, the desktop's byte for byte); the web uses
// sentence case and shows no shortcuts (the console answers none of the desktop's). Only what fits is shown: what
// doesn't apply to what was clicked, or that the page can't run, is left out, and a sub-menu with no choices goes too.
// resources/views/filament/v2/context-menu.blade.php draws the menu and sends picks to the page.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElContextMenuCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** One recording (3b): Open · Transcribe (Audio only) | Pin · Important · Unread · Labels › · Move › | Rename… · Export › | Move to trash. */
    const RECORDING_ONE = [
        'recording.open', 'recording.transcribe',
        '-', 'recording.pin', 'recording.important', 'recording.unread', 'recording.labels', 'recording.moveToProject',
        '-', 'file.rename', 'recording.exportAs',
        '-', 'recording.moveToTrash',
    ];
    /** Several (titled "3 recordings"): Transcribe | Pin · Important · Unread · Labels › · Move › | Export › | Move to trash. */
    const RECORDING_MANY = [
        'recording.transcribe',
        '-', 'recording.pin', 'recording.important', 'recording.unread', 'recording.labels', 'recording.moveToProject',
        '-', 'recording.exportAs',
        '-', 'recording.moveToTrash',
    ];

    /** How many things an action needs (actions.json appliesTo). */
    function fits(applies, n) {
        if (! applies || applies === 'none') return true;
        if (applies === 'one') return n === 1;
        if (applies === 'many') return n >= 2;
        return n >= 1;
    }

    /** An action's words now: its state's ("Unpin" while pinned), as the file writes them (sentence case). */
    function label(action, state) {
        const s = action.state || null;
        if (s && s.key && s.on && state && state[s.key]) return s.on;
        return action.label;
    }

    /**
     * A menu from ids ("-" starts a group). ctx: {targets (ids), state ({pinned, important, unread, hasTranscript…}),
     * runnable (the ids the page can do), children ({id: [items]}: a sub-menu's choices)}. Each item: {action, label,
     * icon, danger, checked, separator, items, choice}. A hairline starts each group that has something in it.
     */
    function menu(actions, ids, ctx) {
        const byId = new Map(actions.map((a) => [a.id, a]));
        const runnable = new Set(ctx.runnable || []);
        const children = ctx.children || {};
        const n = (ctx.targets || []).length;
        const out = [];
        let group = [];
        const flush = () => {
            const shown = [];
            for (const id of group) {
                const a = byId.get(id);
                if (! a || ! fits(a.appliesTo, n) || ! runnable.has(id)) continue;
                const item = { action: id, label: label(a, ctx.state), icon: a.icon || null, danger: !! a.destructive };
                if (a.state && a.state.check) item.checked = !! (ctx.state && ctx.state[a.state.check]);
                if (a.submenu) {
                    const kids = children[id] || [];
                    if (! kids.length) continue;
                    item.items = kids;
                }
                shown.push(item);
            }
            shown.forEach((item, at) => out.push(at === 0 && out.length > 0 ? { ...item, separator: true } : item));
            group = [];
        };
        for (const id of ids) {
            if (id === '-') flush();
            else group.push(id);
        }
        flush();
        return out;
    }

    /** A sub-menu's choices (a label, a project, a format): each sends its sub-menu's action with its value. */
    function choices(action, list) {
        return list.map((c) => ({ action, choice: String(c.value), label: c.label, checked: c.checked }));
    }

    /** "3 recordings": a several-things menu's title (none for one). */
    function title(n, many) {
        return n > 1 ? many.replace(':count', String(n)) : null;
    }

    /** Where focus goes in a menu of `count` items from `at` on a key: arrows wrap, Home and End go to the ends. */
    function move(count, at, key) {
        if (! count) return -1;
        if (key === 'ArrowDown') return at < 0 ? 0 : (at + 1) % count;
        if (key === 'ArrowUp') return at < 0 ? count - 1 : (at - 1 + count) % count;
        if (key === 'Home') return 0;
        if (key === 'End') return count - 1;
        return at;
    }

    /** Where a menu of w × h opens at x, y inside a window of W × H: never off its right or bottom edge. */
    function place(x, y, w, h, W, H) {
        return { x: Math.max(0, Math.min(x, W - w - 4)), y: Math.max(0, Math.min(y, H - h - 4)) };
    }

    return { RECORDING_ONE, RECORDING_MANY, menu, choices, title, label, move, place };
});
