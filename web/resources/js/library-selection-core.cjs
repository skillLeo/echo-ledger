// The v2.0 Library selection's choices with no browser in them (parity plan 3), so a test runs them under Node
// (tests/js/library-selection-core.test.mjs); resources/views/filament/v2/library-selection.blade.php reads the page and
// asks these, through window.ElLibrarySelectionCore.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElLibrarySelectionCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /**
     * Whether ⋮ offers Mark important ('important') or Unmark important ('unimportant') for the selected rows, by the
     * apps' rule (as Pin and Unpin on the desktop): Mark unless every one is important, Unmark unless none is, both when
     * it is mixed. flags: each selected row's data-important ('1' or '0'); anything else (a row not drawn, a selection
     * of every page) is not known, and then both are offered.
     */
    function importantShows(which, flags) {
        if (! flags.length || flags.some((f) => f !== '1' && f !== '0')) return true;
        if (which === 'important') return ! flags.every((f) => f === '1');
        if (which === 'unimportant') return ! flags.every((f) => f === '0');
        return true;
    }

    return { importantShows };
});
