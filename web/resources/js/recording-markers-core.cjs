// The v2.0 recording page's moment markers on the player bar (docs/PLAN_V2_PARITY.md section 4, WEB-REC-010) as plain
// decisions with no browser in them, so a test runs them under Node (tests/js/recording-markers-core.test.mjs);
// resources/views/filament/v2/recording-markers.blade.php asks these through window.ElRecordingMarkersCore. The bar
// is the player's own (transcript-player.blade.php, the same as classic): its ticks are the pauses first, then the
// bookmarks, in the player's order.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElRecordingMarkersCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** m:ss, or h:mm:ss from an hour, as the player's clock. */
    function clock(ms) {
        const s = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const ss = String(s % 60).padStart(2, '0');
        return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + ss : m + ':' + ss;
    }

    /**
     * The moment a tick of the bar stands for: [isPause] and its place among the ticks of its kind, read against the
     * player's data (d.pauses, d.markers). Null when there is none (the bar was drawn again meanwhile).
     */
    function moment(data, isPause, index) {
        const list = isPause ? (data && data.pauses) || [] : (data && data.markers) || [];
        const item = list[index];
        if (! item || ! Number.isFinite(Number(item.at_ms))) return null;
        return { ms: Number(item.at_ms), kind: isPause ? 'pause' : 'bookmark', tag: isPause ? null : item.tag || null };
    }

    /** What a tick says on hover: "Bookmark · 1:05", the bookmark's own tag when it has one, or "Paused at 2:10". */
    function label(item, words) {
        if (! item) return '';
        if (item.kind === 'pause') return words.paused + ' ' + clock(item.ms);
        return (item.tag || words.bookmark) + ' · ' + clock(item.ms);
    }

    return { clock, moment, label };
});
