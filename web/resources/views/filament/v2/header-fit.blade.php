{{--
    Decision 18 (docs/PLAN_V2_1_POLISH.md, an owner request): the page title never loses its words, and no tab or toolbar
    label is cut or wrapped. The top bar and the Library's toolbar row make room in the desktop title row's order
    (resources/js/header-fit-core.cjs decides): each step is laid out here and measured, and the first that fits is kept on
    the page's root, out of Livewire's redraws (data-tb-search; data-lib-lines, -labels, -search, -tabs and -title), for
    the stylesheet to draw ("Decision 18" in console.css). Measured again as the window, the panel or the words change.
    The ⌕ button is the field itself, folded: a click, ⌘K or ⌘F opens it over its row, Esc or leaving it folds it again.
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

        function topBar() {
            const bar = document.querySelector('.v2-tb');
            const search = bar ? bar.querySelector('.v2-tb-search') : null;
            if (! search) return [];
            if (! open('tb', search)) {
                set('tb', core.topBar((state) => {
                    set('tb', state);
                    return bar.scrollWidth <= bar.clientWidth + 1 && inside(bar, search);
                }));
            }
            return [bar];
        }

        function library() {
            const crumbs = document.querySelector('.v2-lib-shell.is-right [data-library-crumbs]');
            if (! crumbs) return [];
            const row = crumbs.parentElement;
            const total = row.querySelector('[data-library-total]');
            const tabs = row.querySelector('[data-library-tabs]');
            const search = row.querySelector('.fi-ta-search-field .fi-input-wrp');
            const tools = row.querySelector('.v2-lib-tools');
            if (! open('lib', search)) {
                set('lib', core.library((state, part) => {
                    set('lib', state);
                    const line1 = [crumbs, total, search];
                    const line2 = [tabs, tools];
                    const items = part === 'row' ? [...line1, ...line2] : part === 'line1' ? line1 : line2;
                    return items.every((el) => inside(row, el) && sameLine(items[0], el));
                }));
            }
            return [row, crumbs, tabs, tools].filter((el) => el !== null);
        }

        // Watched: the rows (the window and the panel), and what their words change (a title, a count, a language's font).
        let watched = [];
        let frame = 0;
        const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(() => schedule()) : null;
        function fit() {
            if (frame) cancelAnimationFrame(frame);
            frame = 0;
            const now = [...topBar(), ...library()];
            if (observer && (now.length !== watched.length || now.some((el, i) => el !== watched[i]))) {
                observer.disconnect();
                now.forEach((el) => observer.observe(el));
                watched = now;
            }
        }
        function schedule() {
            if (! frame) frame = requestAnimationFrame(fit);
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
        document.addEventListener('DOMContentLoaded', fit, { once: true });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(schedule);
    })();
</script>
