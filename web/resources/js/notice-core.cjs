// The rule every in-app notice follows (docs/DESIGN_SYSTEM.md "Notices", since v1.6.1), as plain functions with no
// browser in them, so a test runs them under Node (tests/js/notice-core.test.mjs). The console's toasts and banners
// (resources/views/filament/shell/notices.blade.php) use them through window.ElNoticeCore.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElNoticeCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** A message hides by itself after 6 s, an error after 10 s; Undo keeps its own 5 s. */
    const MESSAGE_MS = 6000;
    const ERROR_MS = 10000;
    const UNDO_MS = 5000;

    /** Below this many pixels a press is still a click; past it, sideways, it is a drag. */
    const DRAG_START_PX = 8;

    /** Past a third of its width it leaves; a flick this fast (px/ms) leaves whatever the distance. */
    const LEAVE_SHARE = 1 / 3;
    const FLICK_PX_PER_MS = 0.5;

    return {
        MESSAGE_MS,
        ERROR_MS,
        UNDO_MS,
        DRAG_START_PX,
        LEAVE_SHARE,
        FLICK_PX_PER_MS,

        /**
         * How long a notice shows, in ms, or null when it has no timer (it is about something still true, or the
         * server marked it persistent). [status] is Filament's (danger, warning, success, info); [duration] what the
         * server asked for: a number, 'persistent', or nothing for the default.
         */
        durationFor(status, duration, stillTrue) {
            if (stillTrue === true || duration === 'persistent') return null;
            if (duration === 'undo') return UNDO_MS;
            const asked = typeof duration === 'number' && duration > 0 ? duration : null;
            if (status === 'danger' && (asked === null || asked === MESSAGE_MS)) return ERROR_MS;
            return asked ?? MESSAGE_MS;
        },

        /** Whether the pointer has moved far enough, and more sideways than up or down, to be a swipe. */
        isDrag(dx, dy) {
            return Math.abs(dx) > DRAG_START_PX && Math.abs(dx) > Math.abs(dy);
        },

        /**
         * What a let-go does: 'left' or 'right' (it leaves that way) or 'back' (it springs back). [velocity] in px/ms,
         * signed like [dx].
         */
        outcome(dx, width, velocity) {
            const far = width > 0 && Math.abs(dx) >= width * LEAVE_SHARE;
            const flick = Math.abs(velocity) >= FLICK_PX_PER_MS && Math.sign(velocity) === Math.sign(dx) && dx !== 0;
            if (!far && !flick) return 'back';
            return dx < 0 ? 'left' : 'right';
        },

        /** The notice fades as it is dragged: fully there at rest, half gone at its full width. */
        opacityFor(dx, width) {
            if (width <= 0) return 1;
            return Math.max(0.2, 1 - Math.min(1, Math.abs(dx) / width) * 0.5);
        },

        /** A trackpad's two-finger swipe arrives as wheel events: this many px of deltaX moves the notice that far. */
        wheelStep(deltaX, deltaY) {
            return Math.abs(deltaX) > Math.abs(deltaY) ? -deltaX : 0;
        },

        /**
         * Where a closed banner is remembered: per visit and per state ("until the state changes or the next visit"),
         * or, for the announcement, per text for good.
         */
        storageKey(id, state) {
            return 'el-notice:' + String(id) + ':' + String(state ?? '');
        },

        /** A short, stable name for a text (the announcement is remembered closed per text). */
        hash(text) {
            let h = 2166136261;
            const s = String(text ?? '');
            for (let i = 0; i < s.length; i++) {
                h ^= s.charCodeAt(i);
                h = Math.imul(h, 16777619);
            }
            return (h >>> 0).toString(36);
        },

        /** The newest of the shown toasts, by the time each was shown: the one Esc closes. */
        newest(items) {
            let best = null;
            for (const item of items) {
                if (item && (best === null || item.at > best.at)) best = item;
            }
            return best;
        },
    };
});
