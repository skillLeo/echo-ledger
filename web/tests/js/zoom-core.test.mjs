// UX-07 and UX-18 on the web console: the page's zoom (resources/js/zoom-core.cjs), with a stand-in document and window.
//   node --test tests/js/zoom-core.test.mjs
import { createRequire } from 'node:module';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const zoom = require('../../resources/js/zoom-core.cjs');

/** A page: its root's style and attributes, a window with storage (or none), and the events each one heard. */
function page({ kept = null, storage = true } = {}) {
    const style = new Map();
    const attributes = new Map();
    const listeners = new Map();
    const on = (name) => (type, fn) => listeners.set(`${name}:${type}`, [...(listeners.get(`${name}:${type}`) ?? []), fn]);
    const store = new Map(kept === null ? [] : [[zoom.KEY, kept]]);
    const told = [];
    const doc = {
        documentElement: {
            style: { setProperty: (k, v) => style.set(k, v), removeProperty: (k) => style.delete(k) },
            setAttribute: (k, v) => attributes.set(k, v),
            removeAttribute: (k) => attributes.delete(k),
        },
        addEventListener: on('document'),
    };
    const win = {
        localStorage: storage ? { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) } : null,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
        dispatchEvent: (e) => told.push([e.type, e.detail]),
        addEventListener: on('window'),
    };
    if (!storage) Object.defineProperty(win, 'localStorage', { get() { throw new Error('blocked'); } });
    const fire = (name, type, event = {}) => (listeners.get(`${name}:${type}`) ?? []).forEach((fn) => fn(event));
    return { doc, win, style, attributes, store, told, fire };
}

test('Chrome\'s steps, 67% to 200%; a kept value that is not one is 100%', () => {
    assert.deepEqual(zoom.STEPS, [67, 75, 80, 90, 100, 110, 125, 150, 175, 200]);
    assert.equal(zoom.parse('125'), 125);
    assert.equal(zoom.parse('120'), 100);
    assert.equal(zoom.parse(null), 100);
    assert.equal(zoom.parse('abc'), 100);
});

test('a step up or down, never past the ends; 0 goes back to 100%', () => {
    assert.equal(zoom.step(100, 1), 110);
    assert.equal(zoom.step(100, -1), 90);
    assert.equal(zoom.step(200, 1), 200);
    assert.equal(zoom.step(67, -1), 67);
    assert.equal(zoom.step(150, 0), 100);
    assert.equal(zoom.step(105, 1), 110, 'between two steps: the next one');
});

test('at 100% the page carries nothing; at another step its root has zoom, --el-zoom and data-el-zoom', () => {
    const p = page({ kept: '125' });
    const api = zoom.install(p.doc, p.win);
    assert.equal(api.now(), 125);
    assert.equal(p.style.get('zoom'), '1.25');
    assert.equal(p.style.get('--el-zoom'), '1.25');
    assert.equal(p.attributes.get('data-el-zoom'), '125');
    api.set(100);
    assert.equal(p.style.size, 0);
    assert.equal(p.attributes.size, 0);
    assert.equal(p.store.has(zoom.KEY), false, '100% keeps nothing');
});

test('the row\'s − and + step it, keep it in this browser and tell the page once per change', () => {
    const p = page();
    const api = zoom.install(p.doc, p.win);
    assert.equal(api.now(), 100);
    assert.equal(api.by(1), 110);
    assert.equal(p.store.get(zoom.KEY), '110');
    api.by(-1);
    api.by(-1);
    assert.equal(api.now(), 90);
    api.set(90);
    assert.deepEqual(p.told, [['el-zoom', 110], ['el-zoom', 100], ['el-zoom', 90]], 'the same level again tells nothing');
    assert.equal(zoom.install(p.doc, p.win), api, 'once per document');
});

test('a browser that keeps nothing: 100% at first, and the zoom still changes for this page', () => {
    const p = page({ storage: false });
    const api = zoom.install(p.doc, p.win);
    assert.equal(api.now(), 100);
    assert.equal(api.by(1), 110);
    assert.equal(p.style.get('zoom'), '1.1');
});

test('another tab\'s choice comes here too; wire:navigate keeps it on the root', () => {
    const p = page({ kept: '80' });
    const api = zoom.install(p.doc, p.win);
    p.store.set(zoom.KEY, '150');
    p.fire('window', 'storage', { key: zoom.KEY });
    assert.equal(api.now(), 150);
    assert.equal(p.style.get('zoom'), '1.5');
    p.fire('window', 'storage', { key: 'something-else' });
    assert.equal(api.now(), 150);
    p.style.clear();
    p.fire('document', 'livewire:navigated');
    assert.equal(p.style.get('zoom'), '1.5');
});
