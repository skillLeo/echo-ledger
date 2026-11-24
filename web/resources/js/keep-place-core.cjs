// UX-25 (PLAN_V2_1_POLISH, an owner request) on the web console: the Library keeps your place, and Back to top, as
// plain decisions with no browser in them, so a test runs them under Node (tests/js/keep-place-core.test.mjs).
// Coming back from a recording (the browser's Back, the recording's "Library" link) the list returns to where it was,
// with the same view, tab, filters and search (all in its address), and the recording you opened keeps a light tint
// for a minute. resources/views/filament/v2/keep-place.blade.php does it in the page.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElKeepPlaceCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** Where this tab keeps the Library's place, and the recording last opened (sessionStorage; signing out forgets them). */
    const KEY = 'el-place:library';
    const OPENED = 'el-place:opened';
    /** A place older than this is not kept: the list starts at its top. */
    const MAX_AGE = 30 * 60 * 1000;
    /** How long the opened row keeps its tint after coming back. */
    const TINT = 60 * 1000;

    /**
     * The Library's place as it was left: its address (path and query: view, tab, filters, search), how far down it was
     * (y), the row at the top of the window (id) and where it stood (offset from the window's top), how many rows were
     * shown (Load more), and when.
     */
    function place(p) {
        return { url: String(p.url), y: Math.max(0, Math.round(Number(p.y) || 0)), id: p.id ? String(p.id) : null,
            offset: Math.round(Number(p.offset) || 0), shown: Math.max(0, Number(p.shown) || 0), at: Number(p.at) || 0 };
    }

    /** Whether a kept place is this list's (the same address) and recent enough to come back to. */
    function restorable(saved, url, now) {
        return !! saved && typeof saved.url === 'string' && typeof saved.at === 'number' && saved.at > 0
            && saved.url === url && now - saved.at <= MAX_AGE;
    }

    /**
     * Whether the list is coming back from a recording: one was opened (a recording's page was shown) after the list
     * was left. Coming back from anywhere else, the list starts at its top as before.
     */
    function cameBack(saved, opened) {
        return !! saved && !! opened && typeof opened.id === 'string' && typeof opened.at === 'number' && opened.at >= saved.at;
    }

    /**
     * How far down to scroll: the row that was at the top of the window where it stood (rowTop: its top on the page now),
     * or, when it is gone (deleted, or no longer in this list), the old place as near as the page allows (maxY).
     */
    function scrollTo(saved, s) {
        const y = typeof s.rowTop === 'number' ? s.rowTop - saved.offset : saved.y;
        return Math.max(0, Math.min(Math.round(y), Math.max(0, s.maxY)));
    }

    /** How many times Load more brings back the rows that were shown (chunk at a time, never past the list's max). */
    function moreNeeded(savedShown, shownNow, chunk, max) {
        const want = Math.min(savedShown, max);
        return want > shownNow ? Math.ceil((want - shownNow) / chunk) : 0;
    }

    /** Whether the opened row still has its tint (since: when the list came back). */
    function tinted(since, now) {
        return typeof since === 'number' && now - since <= TINT;
    }

    /** Back to top shows once the list is more than one screen down. */
    function showsBackToTop(scrollY, viewport) {
        return scrollY > viewport;
    }

    /** Home, Ctrl+Home, or ⌘↑ on a Mac go to the top; never while typing, nor with Shift or Alt (a selection, a word). */
    function toTopKey(e, mac) {
        if (e.typing || e.shiftKey || e.altKey) return false;
        if (e.key === 'Home') return ! e.metaKey;
        return mac && e.key === 'ArrowUp' && e.metaKey && ! e.ctrlKey;
    }

    return { KEY, OPENED, MAX_AGE, TINT, place, restorable, cameBack, scrollTo, moreNeeded, tinted, showsBackToTop, toTopKey };
});
