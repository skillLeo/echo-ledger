// UX-07 and UX-18 on the web console (v21_zoom): the page's zoom in Chrome's steps, from the avatar menu's Zoom row
// "[−] 100% [+]". It is kept in this browser, not synced, as the desktop app keeps it on its computer (a laptop and a
// big monitor want different sizes), and applied in the head before the first paint, so the page doesn't jump. The
// browser's own zoom (⌘+ and ⌘−) still works on top of it. Plain functions over a document and a window, so a test runs
// them under Node (tests/js/zoom-core.test.mjs); the console's head (filament.v2.zoom-script) installs them as
// window.ElZoom. At 100% the page carries nothing; at another step the root has `zoom`, --el-zoom and data-el-zoom, which
// the console's styles read to keep a full-height box one screen tall (resources/css/v2/console.css).
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElZoomCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** Chrome's steps, in percent (the desktop app's too). */
    const STEPS = [67, 75, 80, 90, 100, 110, 125, 150, 175, 200];
    const DEFAULT = 100;
    /** Kept in this browser (never synced). */
    const KEY = 'echoledger-zoom';
    /** Sent on the window when the zoom changes, for the parts that show it (the Zoom row). */
    const EVENT = 'el-zoom';

    /** A kept zoom: one of the steps, else 100%. */
    function parse(text) {
        const n = Number(text);
        return STEPS.includes(n) ? n : DEFAULT;
    }

    /** The step [dir] steps from [now]: the next one up or down, never past the ends; 0 goes back to 100%. */
    function step(now, dir) {
        if (dir === 0) return DEFAULT;
        if (dir > 0) return STEPS.find((s) => s > now) ?? STEPS[STEPS.length - 1];
        return [...STEPS].reverse().find((s) => s < now) ?? STEPS[0];
    }

    /** Draws the page at [level]: nothing on the root at 100%. */
    function apply(doc, level) {
        const root = doc.documentElement;
        if (level === DEFAULT) {
            root.style.removeProperty('zoom');
            root.style.removeProperty('--el-zoom');
            root.removeAttribute('data-el-zoom');
            return;
        }
        root.style.setProperty('zoom', String(level / 100));
        root.style.setProperty('--el-zoom', String(level / 100));
        root.setAttribute('data-el-zoom', String(level));
    }

    /** The kept zoom (100% when none, or when the browser keeps nothing). */
    function load(win) {
        try {
            return parse(win.localStorage.getItem(KEY));
        } catch {
            return DEFAULT;
        }
    }

    /** Keeps [level] (100% keeps nothing); for this page only when the browser keeps nothing. */
    function save(win, level) {
        try {
            if (level === DEFAULT) win.localStorage.removeItem(KEY);
            else win.localStorage.setItem(KEY, String(level));
        } catch {
            // This page only.
        }
    }

    /**
     * Once per document: the kept zoom at once, again after wire:navigate, and the zoom another tab chose. Answers the
     * page's zoom: now(), set(level), by(dir), each change told on the window (EVENT, its detail the level).
     */
    function install(doc, win) {
        if (doc.elZoom) return doc.elZoom;
        let current = load(win);
        apply(doc, current);
        const tell = () => win.dispatchEvent(new win.CustomEvent(EVENT, { detail: current }));
        const api = {
            steps: STEPS,
            now: () => current,
            set(level) {
                const next = parse(String(level));
                save(win, next);
                apply(doc, next);
                const changed = next !== current;
                current = next;
                if (changed) tell();
                return next;
            },
            by(dir) {
                return api.set(step(current, dir));
            },
        };
        win.addEventListener('storage', (e) => {
            if (e.key !== KEY && e.key !== null) return;
            current = load(win);
            apply(doc, current);
            tell();
        });
        doc.addEventListener('livewire:navigated', () => apply(doc, current));
        doc.elZoom = api;
        return api;
    }

    return { STEPS, DEFAULT, KEY, EVENT, parse, step, apply, load, save, install };
});
