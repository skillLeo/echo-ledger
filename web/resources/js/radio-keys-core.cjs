// The settings' radio groups (Settings › Profile's colours and language, Appearance's rows): which choice a key takes,
// as a plain decision with no browser in it (tests/js/radio-keys-core.test.mjs). The arrows move round the group (up
// and down too), Home and End go to its ends, and right to left ← and → swap so they follow the row as it reads.
// resources/views/filament/settings/script.blade.php takes the choice and puts the focus on it.
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

    return { next };
});
