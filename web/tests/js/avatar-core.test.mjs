// DES-08 on the web console: a person's photo that does not load becomes their initials on their colour
// (resources/js/avatar-core.cjs), without a browser: a small stand-in document is enough.
//   node --test tests/js/avatar-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const core = require('../../resources/js/avatar-core.cjs');

/** An element with what the core reads and writes. */
function element(tag, attributes = {}, extra = {}) {
    const el = {
        tagName: tag.toUpperCase(),
        attributes: { ...attributes },
        className: attributes.class || '',
        textContent: '',
        parentNode: null,
        getAttribute(name) { return name === 'class' ? this.className : (this.attributes[name] ?? null); },
        setAttribute(name, value) { if (name === 'class') this.className = value; else this.attributes[name] = String(value); },
        matches(selector) {
            // Only the selector the core uses: img.v2-avatar.is-photo
            const classes = this.className.split(/\s+/);
            return selector === core.PHOTO && this.tagName === 'IMG' && classes.includes('v2-avatar') && classes.includes('is-photo');
        },
        ...extra,
    };
    return el;
}

function documentWith(children, readyState = 'complete') {
    const listeners = {};
    const parent = {
        children,
        replaceChild(next, old) { const at = this.children.indexOf(old); this.children[at] = next; next.parentNode = this; old.parentNode = null; },
    };
    children.forEach((c) => { c.parentNode = parent; });
    return {
        parent,
        readyState,
        listeners,
        createElement: (tag) => element(tag),
        addEventListener(type, fn, capture) { (listeners[type] ||= []).push({ fn, capture }); },
        querySelectorAll(selector) { return parent.children.filter((c) => c.matches(selector)); },
        dispatch(type, target) { (listeners[type] || []).forEach((l) => l.fn({ target })); },
    };
}

const photo = (extra = {}) => element('img', {
    class: 'v2-avatar is-list is-photo', src: '/api/avatars/7/170?signature=x', alt: '', 'aria-hidden': 'true',
    'data-avatar-color': '4', 'data-initials': 'FK', style: 'background-color: var(--el-role-avatar-4)',
}, extra);

test('a photo that fails becomes the person\'s initials on their colour, at its size, hidden from screen readers', () => {
    const img = photo();
    const doc = documentWith([img]);
    const span = core.swap(img, doc);
    assert.equal(span.tagName, 'SPAN');
    assert.equal(span.className, 'v2-avatar is-list is-coloured');
    assert.equal(span.getAttribute('data-avatar-color'), '4');
    assert.equal(span.getAttribute('style'), 'background-color: var(--el-role-avatar-4)');
    assert.equal(span.getAttribute('aria-hidden'), 'true');
    assert.equal(span.textContent, 'FK');
    assert.equal(doc.parent.children[0], span);
});

test('the error is caught on its way down, once per document; other pictures are left alone', () => {
    const img = photo();
    const logo = element('img', { class: 'fi-logo', src: '/logo.svg' });
    const doc = documentWith([img, logo]);
    assert.equal(core.install(doc), true);
    assert.equal(core.install(doc), false, 'installed once');
    assert.equal(doc.listeners.error.length, 1);
    assert.equal(doc.listeners.error[0].capture, true);
    doc.dispatch('error', logo);
    assert.equal(doc.parent.children[1], logo);
    doc.dispatch('error', img);
    assert.equal(doc.parent.children[0].textContent, 'FK');
    // A photo already swapped (no parent any more) is not swapped again.
    assert.equal(core.swap(img, doc), null);
});

test('photos that failed before the page was ready are swapped too; loading and loaded ones stay', () => {
    const gone = photo({ complete: true, naturalWidth: 0 });
    const loading = photo({ complete: false, naturalWidth: 0 });
    const shown = photo({ complete: true, naturalWidth: 128 });
    const doc = documentWith([gone, loading, shown], 'loading');
    core.install(doc);
    assert.equal(doc.parent.children[0], gone, 'nothing is swapped before the page is ready');
    doc.dispatch('DOMContentLoaded', doc);
    assert.equal(doc.parent.children[0].tagName, 'SPAN');
    assert.equal(doc.parent.children[1], loading);
    assert.equal(doc.parent.children[2], shown);
});

test('a photo with no letters (no name, no email) still keeps its colour', () => {
    const img = photo();
    img.setAttribute('data-initials', '');
    const span = core.standIn(img, documentWith([img]));
    assert.equal(span.textContent, '');
    assert.equal(span.getAttribute('data-avatar-color'), '4');
});

test('inside a link whose text is the name, the stand-in draws its letters from data-initials (no text)', () => {
    const img = photo();
    img.setAttribute('class', 'v2-avatar is-xl is-photo is-drawn');
    const span = core.standIn(img, documentWith([img]));
    assert.equal(span.className, 'v2-avatar is-xl is-coloured is-drawn');
    assert.equal(span.getAttribute('data-initials'), 'FK');
    assert.equal(span.textContent, '');
});
