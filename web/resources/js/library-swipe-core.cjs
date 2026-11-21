// The v2.0 Library's swipe at phone width (docs/PLAN_V2_PARITY.md section 3, WEB-LIB-024) as plain decisions with no
// browser in them, so a test runs them under Node (tests/js/library-swipe-core.test.mjs);
// resources/views/filament/v2/library-swipe.blade.php follows the finger and asks these, through
// window.ElLibrarySwipeCore. Keyboard and screen-reader users keep the row's own menu.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElLibrarySwipeCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** Swipes only at phone width, as the apps' list does; a computer's rows keep their clicks. */
    const PHONE_MAX = 720;

    function enabled(viewportWidth) {
        return viewportWidth > 0 && viewportWidth <= PHONE_MAX;
    }

    /** How far a row must travel to count: a quarter of its width, at least 72 (CSS) pixels. */
    function threshold(rowWidth) {
        return Math.max(72, Math.round(rowWidth * 0.25));
    }

    /**
     * Whether a move is a swipe yet (it goes sideways more than down, past a small dead zone) or the page's scroll:
     * 'swipe', 'scroll', or null while it is too small to tell.
     */
    function intent(dx, dy) {
        if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return null;
        return Math.abs(dx) > Math.abs(dy) * 1.5 ? 'swipe' : 'scroll';
    }

    /**
     * What a finished swipe does: 'delete' toward the start of the line's end side (left, or right in right-to-left),
     * 'pin' the other way (Pin, or Unpin when it is pinned), or null when it is too short or mostly vertical.
     */
    function action(dx, dy, rowWidth, rtl) {
        if (intent(dx, dy) !== 'swipe' || Math.abs(dx) < threshold(rowWidth)) return null;
        const towardStart = rtl ? dx > 0 : dx < 0;
        return towardStart ? 'delete' : 'pin';
    }

    /** Which side a row being dragged shows under it: 'delete' toward the end side's start (mirrored in RTL), 'pin' the other way. */
    function reveal(dx, rtl) {
        if (dx === 0) return null;
        const towardStart = rtl ? dx > 0 : dx < 0;
        return towardStart ? 'delete' : 'pin';
    }

    /**
     * Marks the row's record while it is dragged, for the layer drawn under it: data-swipe ("delete" or "pin") and
     * data-swipe-label (Delete, Pin, or Unpin when it is pinned, in the console's language). No side clears both.
     */
    function paint(record, side, pinned, words) {
        if (! record) return;
        if (! side) {
            record.removeAttribute('data-swipe');
            record.removeAttribute('data-swipe-label');
            return;
        }
        record.setAttribute('data-swipe', side);
        record.setAttribute('data-swipe-label', side === 'delete' ? words.delete : (pinned ? words.unpin : words.pin));
    }

    return { PHONE_MAX, enabled, threshold, intent, action, reveal, paint };
});
