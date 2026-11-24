// The settings' radio groups (Settings › Profile's colours and language, Appearance's rows): which choice a key takes,
// and whether the focus goes back to it after a redraw, as plain decisions with no browser in them
// (tests/js/radio-keys-core.test.mjs). The arrows move round the group (up and down too), Home and End go to its ends,
// and right to left ← and → swap so they follow the row as it reads. resources/views/filament/settings/script.blade.php
// takes the choice and puts the focus on it.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElRadioKeysCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** The index a key takes in a group of `count` from `at` (-1: none yet); null when the key is not the group's. */
    function next(count, at, key, rtl) {
        if (! count) return null;
        const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
        const backward = rtl ? 'ArrowRight' : 'ArrowLeft';
        if (key === 'ArrowDown' || key === forward) return at < 0 ? 0 : (at + 1) % count;
        if (key === 'ArrowUp' || key === backward) return at < 0 ? count - 1 : (at - 1 + count) % count;
        if (key === 'Home') return 0;
        if (key === 'End') return count - 1;
        return null;
    }

    /**
     * Whether the focus still goes back to the choice an arrow made, as the page is drawn again: for its 3 s (`refocus`
     * holds until when), until the person moves on. `event` is what they did meanwhile ({ type, inGroup }, null for a
     * redraw): any pointer press ends it (a click on something that takes no focus, or on a control the redraw replaced,
     * is theirs), and so does a key outside that group; another arrow in it keeps it, for the new choice.
     */
    function keepsFocus(refocus, event, now) {
        if (! refocus || now > refocus.until) return false;
        if (! event) return true;
        if (event.type === 'pointerdown') return false;

        return event.type !== 'keydown' || event.inGroup === true;
    }

    return { next, keepsFocus };
});
