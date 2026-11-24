// UX-22 (PLAN_V2_1_POLISH 3b) on the web console: what a right-click menu holds and how its keys move, as plain
// decisions with no browser in them, so a test runs them under Node (tests/js/context-menu-core.test.mjs). The words,
// icons and kinds come from the one action list (resources/js/actions.json, the desktop's byte for byte), in the
// console's language where it has them (say); the web uses sentence case and shows no shortcuts (the console answers
// none of the desktop's). Only what fits is shown: what
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

    /** The list's words in the console's language: say maps its English to it (ListCaptureSessions::menuData). */
    function said(words, say) {
        return (say && Object.prototype.hasOwnProperty.call(say, words) && say[words]) || words;
    }

    /** An action's words now: its state's ("Unpin" while pinned), as the file writes them (sentence case). */
    function label(action, state, say) {
        const s = action.state || null;
        return said(s && s.key && s.on && state && state[s.key] ? s.on : action.label, say);
    }

    /**
     * A menu from ids ("-" starts a group). ctx: {targets (ids), state ({pinned, important, unread, hasTranscript…}),
     * runnable (the ids the page can do), children ({id: [items]}: a sub-menu's choices), say (the words in the
     * console's language)}. Each item: {action, label, icon, danger, checked, separator, items, choice}. A hairline
     * starts each group that has something in it.
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
                const item = { action: id, label: label(a, ctx.state, ctx.say), icon: a.icon || null, danger: !! a.destructive };
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

    /**
     * The menu of the rows it was opened on (each as the page draws it: {id, pinned, important, opened, state, project,
     * labels}) with the page's choices (data: ListCaptureSessions::menuData). Pin says Unpin, Mark as unread says Mark as
     * read, and a tick shows, only when every one is so; Transcribe is there while one of them is Audio only; Labels › ticks each label every one has, then
     * New label…; Move to project › ticks where every one is (No project when none is in one); Export › lists the
     * formats. Several are titled with their count. Returns {items, title, targets}.
     */
    function recordingMenu(data, rows) {
        const n = rows.length;
        const every = (f) => n > 0 && rows.every(f);
        const targets = rows.map((r) => String(r.id));
        const runnable = (data.runnable || []).filter((id) => id !== 'recording.transcribe' || rows.some((r) => r.state === 'audio_only'));
        const ctx = { targets, runnable, say: data.say };
        const has = (r, value) => (r.labels || []).includes(String(value).toLowerCase());
        const labels = choices('recording.labels', (data.labels || []).map((l) => ({ value: l.value, label: l.label, checked: every((r) => has(r, l.value)) })));
        const fresh = menu(data.actions, ['label.new'], ctx).map((item, at) => (at === 0 && labels.length ? { ...item, separator: true } : item));
        const words = data.words || {};
        const children = {
            'recording.labels': [...labels, ...fresh],
            'recording.moveToProject': choices('recording.moveToProject', [
                { value: 'none', label: words.noProject || 'No project', checked: every((r) => ! r.project) },
                ...(data.projects || []).map((p) => ({ value: p.value, label: p.label, checked: every((r) => String(r.project) === String(p.value)) })),
            ]),
            'recording.exportAs': choices('recording.exportAs', data.formats || []),
        };
        const state = { pinned: every((r) => r.pinned), important: every((r) => r.important), unread: every((r) => r.opened === false) };
        const items = menu(data.actions, n > 1 ? RECORDING_MANY : RECORDING_ONE, { ...ctx, state, children });

        return { items, title: title(n, words.many || ':count'), targets };
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

    return { RECORDING_ONE, RECORDING_MANY, menu, choices, title, label, recordingMenu, move, place };
});
