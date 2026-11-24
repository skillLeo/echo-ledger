// Decision 18 (docs/PLAN_V2_1_POLISH.md, an owner request): the page title never loses its words, and no tab or toolbar
// label is cut or wrapped. The web console's header follows the desktop's title row. What each row gives up, in order,
// when it has no room, as a plain decision with no browser in it (tests/js/header-fit-core.test.mjs); the page lays each
// step out and answers whether it fits (filament.v2.header-fit).
// - The top bar: [☰] [workspace] [empty] [search, 180 to 260 px, at the end] [+]. The empty space goes first, then the
//   field shrinks to 180 px (both by the stylesheet), then it is the ⌕ button, which opens the field over the bar.
// - The Library's toolbar row: [Library / title] [count] [tabs] [empty] [search] [Filters] [Sort & display], on one line
//   while it fits, the toolbar's labels going first (their icons stay, with their names). Then two lines, as the
//   desktop's title row and row 2: [title] [count] [empty] [search], and [tabs] [empty] [Filters] [Sort & display]. Each
//   line makes room on its own: the second one's labels go, then its tabs scroll sideways (no fade); on the first the
//   field becomes the ⌕ button, and only when even that leaves no room does the title take more lines, every word kept.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElHeaderFitCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    const TOP_BAR = [{ search: 'field' }, { search: 'button' }];
    const ONE_LINE = [{ labels: 'on' }, { labels: 'off' }];
    const LINE_1 = [{ search: 'field', title: 'whole' }, { search: 'button', title: 'whole' }, { search: 'button', title: 'wraps' }];
    const LINE_2 = [{ labels: 'on', tabs: 'whole' }, { labels: 'off', tabs: 'whole' }, { labels: 'off', tabs: 'scroll' }];

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
     * else 'line1' or 'line2') holds its items whole, each on its line.
     */
    function library(fits) {
        const whole = { search: 'field', tabs: 'whole', title: 'whole' };
        for (const step of ONE_LINE) {
            const state = { lines: 1, ...step, ...whole };
            if (fits(state, 'row')) return state;
        }
        const state = { lines: 2, labels: 'on', ...whole };
        Object.assign(state, firstFit(LINE_2, (step) => fits({ ...state, ...step }, 'line2')));
        Object.assign(state, firstFit(LINE_1, (step) => fits({ ...state, ...step }, 'line1')));

        return state;
    }

    return { TOP_BAR, ONE_LINE, LINE_1, LINE_2, topBar, library };
});
