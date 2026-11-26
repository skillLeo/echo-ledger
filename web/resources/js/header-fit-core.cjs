// Decision 18 (docs/PLAN_V2_1_POLISH.md, an owner request): the page title never loses its words, and no tab or toolbar
// label is cut, wrapped or covered. The web console's header follows the desktop's title row. What each row gives up,
// in order, when it has no room, as a plain decision with no browser in it (tests/js/header-fit-core.test.mjs); the
// page lays each step out and answers whether it fits (filament.v2.header-fit).
// - The top bar: [☰] [workspace] [empty] [search, 180 to 260 px, at the end] [+]. The empty space goes first, then the
//   field shrinks to 180 px (both by the stylesheet), then it is the ⌕ button, which opens the field over the bar.
// - The Library's toolbar row: [Library / title  count] [tabs] [empty] [search] [Filters] [Sort & display], on one line
//   while all of it fits with its words (from 1024 px, with the toolbar's icons too: the list's 120 px). Then two lines, as the desktop's title row over its row 2: [title  count]
//   [empty] [search] [Filters] [Sort & display], and the tabs on a row of their own, which nothing covers. On the title
//   row the toolbar's labels go first (their icons stay, with their names), then the count goes under the title, then
//   the field is the ⌕ button; only when even that leaves no room does the title take more lines, every word kept. The
//   tabs' row scrolls sideways (no fade) only when the tabs alone do not fit it.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElHeaderFitCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const TOP_BAR = [{ search: 'field' }, { search: 'button' }];
    /** One line only with every word: the labels never go to icons before the row splits. */
    const ONE_LINE = { labels: 'on', search: 'field', count: 'beside', title: 'whole', tabs: 'whole' };
    const LINE_1 = [
        { labels: 'on', search: 'field', count: 'beside', title: 'whole' },
        { labels: 'off', search: 'field', count: 'beside', title: 'whole' },
        { labels: 'off', search: 'field', count: 'under', title: 'whole' },
        { labels: 'off', search: 'button', count: 'under', title: 'whole' },
        { labels: 'off', search: 'button', count: 'under', title: 'wraps' },
    ];
    const LINE_2 = [{ tabs: 'whole' }, { tabs: 'scroll' }];

    /** The first of `steps` that fits, else the last one (it is what is left). */
    function firstFit(steps, fits) {
        for (const step of steps) {
            if (fits(step)) return step;
        }

        return steps[steps.length - 1];
    }

    /** The top bar's search: `fits(state)` lays the bar out so and answers whether it holds everything. */
    function topBar(fits) {
        return { ...firstFit(TOP_BAR, fits) };
    }

    /**
     * The Library's toolbar row: `fits(state, part)` lays the row out so and answers whether `part` ('row' on one line,
     * else 'line1', the title row, or 'line2', the tabs' row) holds its items whole, each on its line. `keepOneLine`:
     * from the console's medium width (1024 px) the list starts within 120 px of the window's top (PLAN_LIBRARY_FILTERS
     * section 4, one toolbar row), so there the one line gives up the toolbar's labels before it splits; narrower, the
     * row splits first and keeps them.
     */
    function library(fits, keepOneLine = false) {
        for (const labels of keepOneLine ? ['on', 'off'] : ['on']) {
            const one = { lines: 1, ...ONE_LINE, labels };
            if (fits(one, 'row')) return one;
        }
        const state = { lines: 2, ...ONE_LINE };
        Object.assign(state, firstFit(LINE_2, (step) => fits({ ...state, ...step }, 'line2')));
        Object.assign(state, firstFit(LINE_1, (step) => fits({ ...state, ...step }, 'line1')));

        return state;
    }

    /**
     * A fit's gate: whether what it depends on (the rows' widths, their words, the language) changed since the last fit
     * that ran. Each step of a fit writes on the page's root, a whole style and layout pass, and a fit is asked for on
     * every redraw (a search's keystroke, the list's poll): an unchanged one returns at once. `force` runs it anyway (the
     * fonts came, and the same words take another width).
     */
    function gate() {
        let last = null;

        return (parts, force = false) => {
            const now = JSON.stringify(parts);
            if (! force && now === last) return false;
            last = now;

            return true;
        };
    }

    return { TOP_BAR, ONE_LINE, LINE_1, LINE_2, topBar, library, gate };
});
