{{--
    The new Library's filter panel and keys (2.0.0-p, docs/PLAN_LIBRARY_FILTERS.md sections 1 and 5), only under
    ui_v2.library. elV2LibraryShell keeps the panel open or closed (remembered by this browser, closed by default) and
    makes it a drawer over the list when the window is narrower than --el-breakpoint-panel (tokens.css), a modal dialog
    then; the keys: F opens or closes the filters while the Library has the focus (WCAG 2.1.4), ⌘F / Ctrl+F goes to the
    search, and Esc closes the panel before it clears a selection (the numbers are the frame's: filament.v2.frame-script).
    ElLibraryKeysCore.shellKey decides (tests/js). On every page
    of the console, ⌘[ / ⌘] (Ctrl+[ / Ctrl+] off a Mac) go Back and Forward (ElLibraryKeysCore.historyKey); a step
    taken while the Library is still answering the last one waits for that answer (ElLibraryKeysCore.historyGate).
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
                // The design token --el-breakpoint-panel (1280): narrower than it, the panel is a drawer over the list. With
                // the three levels (config.side) the filters come from the right and stay beside the list from --el-breakpoint-wide.
                const panel = getComputedStyle(document.documentElement).getPropertyValue((config && config.side) || '--el-breakpoint-panel').trim() || '0';
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

        // ⌘[ / ⌘] (Ctrl+[ / Ctrl+] off a Mac): Back and Forward on every page of the console (ElLibraryKeysCore.historyKey),
        // asked as the key bubbles up, so an editor of rich text that uses it (outdent, indent) has claimed it first.
        const mac = /mac|iphone|ipad/i.test(navigator.userAgentData?.platform || navigator.platform || '');
        document.addEventListener('keydown', (e) => {
            const core = window.ElLibraryKeysCore;
            if (! core || typeof core.historyKey !== 'function') return;
            // Any field left over (an input, a text area, a list, an editor that did not claim the key) and a dialog keep the page and the typing.
            const where = (t) => ! (t instanceof Element) ? t
                : (t.closest('[contenteditable]:not([contenteditable="false"])') !== null ? { editable: true } : (t.closest('input, textarea, select') !== null ? { field: true } : t));
            const way = core.historyKey({ key: e.key, code: e.code, ctrlKey: e.ctrlKey, metaKey: e.metaKey, altKey: e.altKey, shiftKey: e.shiftKey,
                defaultPrevented: e.defaultPrevented, target: where(e.target) }, { mac, modal: document.querySelector('.fi-modal-open[aria-modal="true"]') !== null });
            if (! way) return;
            e.preventDefault();
            if (way === 'stay') return;
            e.stopImmediatePropagation();
            if (way === 'back') window.history.back(); else window.history.forward();
        });

        // Back and Forward while the Library is still answering the last step (ElLibraryKeysCore.historyGate): the step
        // waits for that answer, then its address and state are put back as it left them and it goes through, so a late
        // answer never rewrites the address of the step after it, and that step replaces its entry instead of adding one.
        // Held before Livewire hears it (capture, on the window). Each request is counted back on respond(), which every
        // ending reaches (ElLibraryKeysCore.watchRequest). A held step goes through ten seconds after the first one held at
        // the latest, also while a request hangs and others end (ElLibraryKeysCore.historyGuard), and is dropped if the
        // person went somewhere since (a filter or a tab pushed an entry).
        let gate = null;
        let guard = null;
        let replaying = false;
        let pushes = 0;
        const push = history.pushState.bind(history);
        history.pushState = function (...args) { pushes += 1; return push(...args); };
        const where = () => ({ key: window.navigation?.currentEntry?.key ?? null, pushes });
        const library = (component) => {
            const el = component && component.el;
            return el instanceof Element && (el.querySelector('.v2-library') !== null || el.matches('.v2-library'));
        };
        // A step handed back (ElLibraryKeysCore.historyGuard: the last request out ended, or the limit came).
        const replay = (step) => {
            // After Livewire is done with the answer: its render a frame later, and the previous step's reset after it.
            requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(() => {
                if (! window.ElLibraryKeysCore.stillCurrent(step.at, where())) return;
                history.replaceState(step.state, '', step.url);
                replaying = true;
                try {
                    window.dispatchEvent(new PopStateEvent('popstate', { state: step.state }));
                } finally {
                    replaying = false;
                }
            }, 0)));
        };
        const timers = { set: (fn, ms) => setTimeout(fn, ms), clear: (id) => clearTimeout(id) };
        const watch = () => {
            window.Livewire.hook('commit', ({ component, respond }) => {
                const core = window.ElLibraryKeysCore;
                if (! library(component) || ! core || typeof core.historyGuard !== 'function') return;
                gate = gate || core.historyGate();
                guard = guard || core.historyGuard(gate, timers, replay, 10000);
                core.watchRequest(gate, { respond }, guard.handBack);
            });
        };
        if (window.Livewire && typeof window.Livewire.hook === 'function') watch(); else document.addEventListener('livewire:init', watch);
        window.addEventListener('popstate', (e) => {
            if (replaying || guard === null) return;
            let state = e.state;
            try { state = structuredClone(e.state); } catch (err) { /* kept as it is */ }
            if (guard.pop({ state, url: location.href, at: where() }, Date.now()) === 'hold') e.stopImmediatePropagation();
        }, true);

        // The keys, before the list's own (library-keys): ElLibraryKeysCore.shellKey decides.
        document.addEventListener('keydown', (e) => {
            const shellEl = document.querySelector('.v2-lib-shell');
            const core = window.ElLibraryKeysCore;
            if (! shellEl || ! core || typeof core.shellKey !== 'function') return;
            const data = window.Alpine ? window.Alpine.$data(shellEl) : null;
            const field = (t) => t instanceof Element && t.closest('input, textarea, select, [contenteditable="true"]') !== null ? { typing: true } : t;
            // The single key F answers only while the Library has the focus (WCAG 2.1.4): the list, its toolbar or the panel.
            const act = core.shellKey({ key: e.key, code: e.code, ctrlKey: e.ctrlKey, metaKey: e.metaKey, altKey: e.altKey, defaultPrevented: e.defaultPrevented, target: field(e.target) }, {
                modal: document.querySelector('.fi-modal-open[aria-modal="true"]') !== null, panelOpen: !! (data && data.filtersOpen),
                focused: e.target instanceof Element && shellEl.contains(e.target),
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
            }
        }, true);
    })();
</script>
