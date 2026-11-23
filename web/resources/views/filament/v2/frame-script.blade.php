{{--
    The three levels' behaviour (docs/PLAN_NAV_3_LEVELS.md section 6), only under ConsoleV2::frame(). elV2Frame keeps
    each place's panel open or folded (remembered by this browser for each place), folds it away under the flyout width
    (--el-breakpoint-flyout), where a rail icon opens it over the page and choosing closes it, remembers where you left
    each place (its page with its filters and view) so its rail icon brings you back there, and starts a place over when
    its icon is clicked while you are in it. The keys: ⌘1…⌘6 (Ctrl off a Mac) the rail's places, Alt+1…9 the panel's
    first nine, ⌘\ the panel. ElFrameCore decides (resources/js/frame-core.cjs, tests/js).
--}}
<script>
    {!! file_get_contents(resource_path('js/frame-core.cjs')) !!}
</script>
<script>
    (() => {
        if (window.elV2FrameReady) return;
        window.elV2FrameReady = true;
        const store = {
            get(key) { try { return localStorage.getItem(key); } catch (e) { return null; } },
            set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* a private window */ } },
            drop(key) { try { localStorage.removeItem(key); } catch (e) { /* a private window */ } },
        };
        const token = (name, fallback) => {
            const css = getComputedStyle(document.documentElement);
            return css.getPropertyValue(name).trim() || css.getPropertyValue(fallback).trim();
        };
        const register = () => window.Alpine.data('elV2Frame', (config) => ({
            place: config.place,
            panels: config.panels || [],
            flyout: null,
            narrow: false,
            init() {
                const core = window.ElFrameCore;
                const flyoutAt = window.matchMedia('(min-width: ' + token('--el-breakpoint-flyout', '--el-breakpoint-medium') + ')');
                const decide = () => {
                    this.narrow = ! flyoutAt.matches;
                    this.flyout = null;
                    const sidebar = window.Alpine.store('sidebar');
                    // On a computer the panel sits beside the page as this place was left; under the flyout width it folds away.
                    if (window.matchMedia('(min-width: ' + token('--el-breakpoint-medium', '--el-breakpoint-medium') + ')').matches) {
                        const open = ! this.narrow && core.panelOpen(store.get('el-nav-panel:' + this.place), this.hasPanel(this.place));
                        open ? sidebar.open() : sidebar.close();
                    }
                };
                decide();
                flyoutAt.addEventListener('change', decide);
                // Where you are in this place: its page with its filters and view, for its rail icon next time.
                if (this.place) {
                    const save = () => store.set('el-nav-last:' + this.place, location.pathname + location.search + location.hash);
                    save();
                    window.addEventListener('pagehide', save);
                    window.addEventListener('popstate', save);
                    // The Library writes its filters and view into the address after each of its requests.
                    window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => setTimeout(save, 0)));
                }
            },
            hasPanel(key) { return this.panels.includes(key); },
            shown() { return this.flyout ?? this.place; },
            railClick(e, key) {
                if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                const core = window.ElFrameCore;
                const fold = core.railFlyout({ narrow: this.narrow || ! window.matchMedia('(min-width: ' + token('--el-breakpoint-medium', '--el-breakpoint-medium') + ')').matches,
                    hasPanel: this.hasPanel(key), flyout: this.flyout, key });
                if (fold !== 'go') {
                    e.preventDefault();
                    this.flyout = fold === 'open' ? key : null;
                    if (fold === 'open') this.$nextTick(() => this.$root.querySelector('[data-panel="' + key + '"] .v2-panel-body a, [data-panel="' + key + '"] .v2-panel-body input')?.focus());
                    return;
                }
                const to = core.railTarget({ key, current: this.place, home: e.currentTarget.getAttribute('href'), last: store.get('el-nav-last:' + key), origin: location.origin });
                if (to.reset) store.drop('el-nav-last:' + key);
                e.preventDefault();
                window.location.href = to.go;
            },
            togglePanel() {
                if (this.narrow || ! window.matchMedia('(min-width: ' + token('--el-breakpoint-medium', '--el-breakpoint-medium') + ')').matches) {
                    this.flyout = this.flyout !== null ? null : (this.hasPanel(this.place) ? this.place : null);
                    return;
                }
                const sidebar = window.Alpine.store('sidebar');
                if (! this.hasPanel(this.place)) return;
                sidebar.isOpen ? sidebar.close() : sidebar.open();
                store.set('el-nav-panel:' + this.place, sidebar.isOpen ? 'open' : 'closed');
            },
            closeFlyout(focusRail) {
                if (this.flyout === null) return;
                const key = this.flyout;
                this.flyout = null;
                if (focusRail) this.$nextTick(() => this.$root.querySelector('[data-rail="' + key + '"]')?.focus());
            },
        }));
        if (window.Alpine) register(); else document.addEventListener('alpine:init', register);

        // The frame's keys, on every page (ElFrameCore.frameKey).
        const mac = /mac|iphone|ipad/i.test(navigator.userAgentData?.platform || navigator.platform || '');
        document.addEventListener('keydown', (e) => {
            const core = window.ElFrameCore;
            const frame = document.querySelector('.v2-frame-inner');
            if (! core || ! frame) return;
            const rail = Array.from(frame.querySelectorAll('[data-rail-shortcut]')).sort((a, b) => Number(a.dataset.railShortcut) - Number(b.dataset.railShortcut));
            const items = Array.from(document.querySelectorAll('.v2-panel:not([hidden]) [data-shortcut]')).filter((a) => a.dataset.shortcut !== '')
                .sort((a, b) => Number(a.dataset.shortcut) - Number(b.dataset.shortcut));
            const where = (t) => ! (t instanceof Element) ? t
                : (t.closest('[contenteditable]:not([contenteditable="false"])') !== null ? { editable: true } : (t.closest('input, textarea, select') !== null ? { field: true } : t));
            const act = core.frameKey({ key: e.key, code: e.code, ctrlKey: e.ctrlKey, metaKey: e.metaKey, altKey: e.altKey, shiftKey: e.shiftKey,
                defaultPrevented: e.defaultPrevented, target: where(e.target) }, {
                mac, modal: document.querySelector('.fi-modal-open[aria-modal="true"]') !== null, rail: rail.length, items: Math.min(9, items.length),
            });
            if (! act) return;
            e.preventDefault();
            const data = window.Alpine ? window.Alpine.$data(frame) : null;
            if (act.do === 'panel') {
                data?.togglePanel();
            } else if (act.do === 'rail' && rail[act.at]) {
                // As a click on that icon: where you left that place, or the place started over when you are in it.
                rail[act.at].dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
            } else if (act.do === 'item' && items[act.at]) {
                window.location.href = items[act.at].href;
            }
        });
    })();
</script>
