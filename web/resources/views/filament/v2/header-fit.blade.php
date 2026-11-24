{{--
    Decision 18 (docs/PLAN_V2_1_POLISH.md, an owner request): the page title never loses its words, and no tab or toolbar
    label is cut or wrapped. The top bar and the Library's toolbar row make room in the desktop title row's order
    (resources/js/header-fit-core.cjs decides): each step is laid out here and measured, and the first that fits is kept on
    the page's root, out of Livewire's redraws (data-tb-search; data-lib-lines, -labels, -search, -tabs and -title), for
    the stylesheet to draw ("Decision 18" in console.css). Measured again as the window, the panel, the words or the
    language change, and only then (the gate): a redraw that changed none of them (a keystroke's answer, the list's poll)
    lays nothing out. The ⌕ button is the field itself, folded: a click, ⌘K or ⌘F opens it over its row, Esc or leaving
    it folds it again.
--}}
<script>
    {!! file_get_contents(resource_path('js/header-fit-core.cjs')) !!}

    (() => {
        if (window.ElHeaderFit) return;
        const core = window.ElHeaderFitCore;
        const root = document.documentElement;
        const set = (prefix, state) => Object.entries(state).forEach(([key, value]) => root.setAttribute(`data-${prefix}-${key}`, value));
        const shown = (el) => el !== null && el.getClientRects().length > 0;
        // Whether el is within the row's two sides, and whether b is on a's line (their boxes overlap top to bottom).
        const inside = (row, el) => {
            if (! shown(el)) return true;
            const r = row.getBoundingClientRect();
            const e = el.getBoundingClientRect();
            return e.left >= r.left - 1 && e.right <= r.right + 1;
        };
        const sameLine = (a, b) => {
            if (! shown(a) || ! shown(b)) return true;
            const x = a.getBoundingClientRect();
            const y = b.getBoundingClientRect();
            return x.top < y.bottom - 1 && y.top < x.bottom - 1;
        };
        // While a ⌕ button's field is open over its row, the row stays as it is until it folds again.
        const open = (prefix, field) => root.getAttribute(`data-${prefix}-search`) === 'button' && field !== null && field.contains(document.activeElement);

        // The rows and what they hold (each fit finds them again: a redraw may have replaced them).
        function parts() {
            const bar = document.querySelector('.v2-tb');
            const crumbs = document.querySelector('.v2-lib-shell.is-right [data-library-crumbs]');
            const row = crumbs ? crumbs.parentElement : null;
            return {
                bar, tbSearch: bar ? bar.querySelector('.v2-tb-search') : null, crumbs, row,
                search: row ? row.querySelector('.fi-ta-search-field .fi-input-wrp') : null,
                tabs: row ? row.querySelector('[data-library-tabs]') : null, tools: row ? row.querySelector('.v2-lib-tools') : null,
            };
        }

        function topBar(p) {
            set('tb', core.topBar((state) => {
                set('tb', state);
                return p.bar.scrollWidth <= p.bar.clientWidth + 1 && inside(p.bar, p.tbSearch);
            }));
        }

        // From the medium width the list keeps within 120 px of the top: one toolbar row (keepOneLine).
        const medium = window.matchMedia('(min-width: 1024px)');
        function library(p) {
            set('lib', core.library((state, part) => {
                set('lib', state);
                // The title row (the title with its count, the search, the toolbar), the tabs' row, or both on one line.
                const line1 = [p.crumbs, p.search, p.tools];
                const items = part === 'row' ? [...line1, p.tabs] : part === 'line1' ? line1 : [p.tabs];
                return items.every((el) => inside(p.row, el) && sameLine(items[0], el));
            }, medium.matches));
        }

        // Watched: the rows (the window and the panel), and what their words change (a title, a count, a language's font).
        let watched = [];
        let frame = 0;
        const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(() => schedule()) : null;
        const needed = core.gate();
        const words = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : '');
        function fit(force = false) {
            if (frame) cancelAnimationFrame(frame);
            frame = 0;
            const p = parts();
            const now = [p.bar, p.row, p.crumbs, p.tabs, p.tools].filter((el) => el !== null);
            if (observer && (now.length !== watched.length || now.some((el, i) => el !== watched[i]))) {
                observer.disconnect();
                now.forEach((el) => observer.observe(el));
                watched = now;
            }
            // A ⌕ field open over its row: the rows stay as they are until it folds (and nothing is noted meanwhile).
            if (open('tb', p.tbSearch) || open('lib', p.search)) return;
            if (! needed({
                bar: p.bar ? p.bar.clientWidth : 0, row: p.row ? p.row.clientWidth : 0,
                words: [p.crumbs, p.tabs, p.tools].map(words).join('|'), lang: (root.lang || '') + ' ' + (root.dir || ''), medium: medium.matches,
            }, force)) return;
            if (p.tbSearch) topBar(p);
            if (p.crumbs) library(p);
        }
        function schedule() {
            if (! frame) frame = requestAnimationFrame(() => fit());
        }
        window.ElHeaderFit = { fit };

        // A ⌕ field folds as the focus leaves it, and on Esc, keeping its words (a search field's Esc would clear them):
        // the Library's ⌕ then looks on while it holds a search. The next Tab goes on from it.
        const fields = { tb: '.v2-tb-search', lib: '.v2-lib-shell.is-right .fi-ta-search-field' };
        const folding = (prefix) => root.getAttribute(`data-${prefix}-search`) === 'button';
        document.addEventListener('focusout', () => { if (folding('tb') || folding('lib')) schedule(); }, true);
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape' || ! (e.target instanceof HTMLInputElement)) return;
            if (Object.entries(fields).some(([prefix, selector]) => folding(prefix) && e.target.closest(selector))) {
                e.preventDefault();
                e.target.blur();
            }
        }, true);
        const redraws = () => window.Livewire.hook('morphed', () => schedule());
        if (window.Livewire) redraws(); else document.addEventListener('livewire:init', redraws, { once: true });
        document.addEventListener('DOMContentLoaded', () => fit(), { once: true });
        // The fonts came: the same words may take another width.
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => fit(true));
    })();
</script>
