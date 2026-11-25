// DES-08: a person's photo that does not load (gone, expired, offline) becomes their initials on their colour, never a
// broken picture. Plain functions over a document, so a test runs them under Node (tests/js/avatar-core.test.mjs); the
// new console's head (resources/views/filament/v2/head.blade.php) installs them through window.ElAvatarCore. The photo
// (x-el.avatar) carries what its stand-in needs: its colour (data-avatar-color and its background) and data-initials.
(function (root, factory) {
    const core = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = core;
    } else {
        root.ElAvatarCore = core;
    }
})(typeof self !== 'undefined' ? self : this, function () {
    /** A person's photo (x-el.avatar). */
    const PHOTO = 'img.v2-avatar.is-photo';

    /**
     * The initials that stand in for [img]: its size, on its colour, with its letters (written, or drawn by CSS from
     * data-initials when the photo is is-drawn), hidden from screen readers too.
     */
    function standIn(img, doc) {
        const span = doc.createElement('span');
        const classes = String(img.className || '').split(/\s+/).filter(Boolean);
        const initials = img.getAttribute('data-initials') || '';
        span.className = classes.map((c) => (c === 'is-photo' ? 'is-coloured' : c)).join(' ');
        span.setAttribute('data-avatar-color', img.getAttribute('data-avatar-color') || '');
        span.setAttribute('style', img.getAttribute('style') || '');
        if (classes.includes('is-drawn')) span.setAttribute('data-initials', initials);
        else span.textContent = initials;
        span.setAttribute('aria-hidden', 'true');
        return span;
    }

    /** Puts the initials in place of a photo that failed; anything else is left alone. Answers the stand-in, or null. */
    function swap(img, doc) {
        if (!img || typeof img.matches !== 'function' || !img.matches(PHOTO) || !img.parentNode) return null;
        const span = standIn(img, doc);
        img.parentNode.replaceChild(span, img);
        return span;
    }

    /** True for a photo the browser has given up on (loaded, with nothing to draw). */
    function failed(img) {
        return img.complete === true && img.naturalWidth === 0 && Boolean(img.getAttribute('src'));
    }

    /**
     * Once per document: a photo that fails from now on (also one Livewire draws later) turns into initials, and so do
     * the ones that failed before the page was ready.
     */
    function install(doc) {
        if (doc.elAvatarCore) return false;
        doc.elAvatarCore = true;
        // An image's error does not bubble: it is caught on its way down.
        doc.addEventListener('error', (e) => swap(e.target, doc), true);
        const sweep = () => Array.from(doc.querySelectorAll(PHOTO)).filter(failed).forEach((img) => swap(img, doc));
        if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', sweep);
        else sweep();
        return true;
    }

    return { PHOTO, standIn, swap, failed, install };
});
