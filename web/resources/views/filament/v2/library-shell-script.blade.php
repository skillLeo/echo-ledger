{{--
    The new Library's filter panel and keys (2.0.0-p, docs/PLAN_LIBRARY_FILTERS.md sections 1 and 5), only under
    ui_v2.library. elV2LibraryShell keeps the panel open or closed (remembered by this browser, closed by default) and
    makes it a drawer over the list when the window is narrower than --el-breakpoint-panel (tokens.css); the keys: F opens or
    closes the filters, ⌘F / Ctrl+F goes to the search, Esc closes the panel before it clears a selection, and ⌘1…⌘9
    (Alt+1…9 too) opens the sidebar's first nine places. ElLibraryKeysCore.shellKey decides (tests/js).
--}}
<script>
    (() => {
        if (window.elV2LibraryShell) return;
        window.elV2LibraryShell = true;
        const remembered = () => { try { return localStorage.getItem('el-lib-filters') === 'open'; } catch (e) { return false; } };
        const remember = (open) => { try { localStorage.setItem('el-lib-filters', open ? 'open' : 'closed'); } catch (e) { /* private window */ } };
        const register = () => window.Alpine.data('elV2LibraryShell', (config) => ({
            filtersOpen: false,
            drawer: false,
            init() {
                // The design token --el-breakpoint-panel (1280): narrower than it, the panel is a drawer over the list.
                const panel = getComputedStyle(document.documentElement).getPropertyValue('--el-breakpoint-panel').trim() || '0';
                const wide = window.matchMedia('(min-width: ' + panel + ')');
                const decide = () => { this.drawer = ! wide.matches; this.filtersOpen = this.drawer ? false : remembered(); };
                decide();
                wide.addEventListener('change', decide);
                this.onFilters = (e) => {
                    const d = e.detail || {};
                    if (d.action === 'close') this.closeFilters(); else if (d.action === 'open' || d.open) this.openFilters(d.section); else this.toggleFilters();
                };
                this.onNewView = () => this.$wire.mountAction('saveView');
                window.addEventListener('el-filters', this.onFilters);
                window.addEventListener('el-new-view', this.onNewView);
                if (config && config.compose) this.$nextTick(() => this.$wire.mountAction('saveView'));
            },
            destroy() {
                window.removeEventListener('el-filters', this.onFilters);
                window.removeEventListener('el-new-view', this.onNewView);
            },
            toggleFilters() { this.filtersOpen ? this.closeFilters() : this.openFilters(); },
            openFilters(section) {
                this.filtersOpen = true;
                if (! this.drawer) remember(true);
                this.$nextTick(() => {
                    const panel = this.$el.querySelector('#v2-lib-filters');
                    const target = section ? panel?.querySelector('[data-section="' + section + '"]') : null;
                    panel?.querySelectorAll('.is-target').forEach((el) => el.classList.remove('is-target'));
                    if (target) { target.classList.add('is-target'); target.scrollIntoView({ block: 'nearest' }); }
                    (target?.querySelector('input') || panel?.querySelector('input, button'))?.focus({ preventScroll: !! target });
                });
            },
            closeFilters() {
                this.filtersOpen = false;
                if (! this.drawer) remember(false);
                this.$nextTick(() => this.$el.querySelector('[data-filters-button]')?.focus());
            },
        }));
        if (window.Alpine) register(); else document.addEventListener('alpine:init', register);

        // The keys, before the list's own (library-keys): ElLibraryKeysCore.shellKey decides.
        document.addEventListener('keydown', (e) => {
            const shellEl = document.querySelector('.v2-lib-shell');
            const core = window.ElLibraryKeysCore;
            if (! shellEl || ! core || typeof core.shellKey !== 'function') return;
            const places = Array.from(document.querySelectorAll('[data-shortcut]')).filter((a) => a.dataset.shortcut !== '')
                .sort((a, b) => Number(a.dataset.shortcut) - Number(b.dataset.shortcut));
            const data = window.Alpine ? window.Alpine.$data(shellEl) : null;
            const field = (t) => t instanceof Element && t.closest('input, textarea, select, [contenteditable="true"]') !== null ? { typing: true } : t;
            const act = core.shellKey({ key: e.key, code: e.code, ctrlKey: e.ctrlKey, metaKey: e.metaKey, altKey: e.altKey, defaultPrevented: e.defaultPrevented, target: field(e.target) }, {
                modal: document.querySelector('.fi-modal-open[aria-modal="true"]') !== null, panelOpen: !! (data && data.filtersOpen), places: Math.min(9, places.length),
            });
            if (! act) return;
            e.preventDefault();
            e.stopImmediatePropagation();
            if (act.do === 'search') {
                const box = shellEl.querySelector('.fi-ta-search-field input, input[type="search"]');
                box?.focus();
                box?.select();
            } else if (act.do === 'toggle-filters') {
                data?.toggleFilters();
            } else if (act.do === 'close-filters') {
                data?.closeFilters();
            } else if (act.do === 'place' && places[act.at]) {
                window.location.href = places[act.at].href;
            }
        }, true);
    })();
</script>
