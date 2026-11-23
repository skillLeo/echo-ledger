{{--
    The three levels' behaviour (docs/PLAN_NAV_3_LEVELS.md section 6), only under ConsoleV2::frame(). elV2Frame keeps
    each place's panel open or folded (remembered by this browser for each place), folds it away under the flyout width
    (--el-breakpoint-flyout), where a rail icon opens it over the page and choosing closes it, remembers where you left
    each place (its page with its filters and view) so its rail icon brings you back there, and starts a place over when
    its icon is clicked while you are in it (under the flyout width too, its flyout opening with it). A flyout gives focus
    back to what opened it after Esc, ⊟ or a click outside, and its rail icon says aria-expanded. The memory is one
    person's in one workspace (config.scope), anyone else's goes, and the login page forgets it all (forget-places).
    The keys: ⌘1…⌘6 (Ctrl off a Mac) the rail's places, Alt+1…9 the panel's first nine (never in a field), ⌘\ the
    panel. ElFrameCore decides (resources/js/frame-core.cjs, tests/js).
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
        const session = {
            take(key) { try { const v = sessionStorage.getItem(key); sessionStorage.removeItem(key); return v; } catch (e) { return null; } },
            set(key, value) { try { sessionStorage.setItem(key, value); } catch (e) { /* a private window */ } },
        };
        const keys = () => { try { return Object.keys(localStorage); } catch (e) { return []; } };
        const wide = () => window.matchMedia('(min-width: ' + token('--el-breakpoint-medium', '--el-breakpoint-medium') + ')').matches;
        const register = () => window.Alpine.data('elV2Frame', (config) => ({
            place: config.place,
            panels: config.panels || [],
            scope: config.scope || '',
            flyout: null,
            opener: null,
            narrow: false,
            init() {
                const core = window.ElFrameCore;
                // This browser's memory is this person's in this workspace: anyone else's (and the unscoped kind) goes.
                core.staleMemory(keys(), this.scope).forEach((k) => store.drop(k));
                const flyoutAt = window.matchMedia('(min-width: ' + token('--el-breakpoint-flyout', '--el-breakpoint-medium') + ')');
                const decide = () => {
                    this.narrow = ! flyoutAt.matches;
                    this.flyout = null;
                    const sidebar = window.Alpine.store('sidebar');
                    // On a computer the panel sits beside the page as this place was left; under the flyout width it folds away.
                    if (wide()) {
                        const open = ! this.narrow && core.panelOpen(store.get(this.memory('panel', this.place)), this.hasPanel(this.place));
                        open ? sidebar.open() : sidebar.close();
                    }
                };
                decide();
                flyoutAt.addEventListener('change', decide);
                // The place started over from its rail icon under the flyout width: its flyout opens on the new page.
                const reopen = session.take('el-nav-flyout');
                if (reopen !== null && reopen === this.place && this.narrow && this.hasPanel(this.place)) {
                    this.openFlyout(this.place, this.$root.querySelector('[data-rail="' + this.place + '"]'));
                }
                // Where you are in this place: its page with its filters and view, for its rail icon next time.
                if (this.place) {
                    const save = () => store.set(this.memory('last', this.place), location.pathname + location.search + location.hash);
                    save();
                    window.addEventListener('pagehide', save);
                    window.addEventListener('popstate', save);
                    // The Library writes its filters and view into the address after each of its requests.
                    window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => setTimeout(save, 0)));
                }
            },
            memory(what, place) { return window.ElFrameCore.memoryKey(this.scope, what, place); },
            hasPanel(key) { return this.panels.includes(key); },
            // A dialog opened from the flyout (⋮ › Rename, + New label) is the flyout's own: a key, a click or the focus in
            // it never closes the flyout, so focus comes back to what opened the dialog when it closes.
            inDialog(target) { return window.ElFrameCore.inDialog(target); },
            shown() { return this.flyout ?? this.place; },
            folded() { return this.narrow || ! wide(); },
            // The rail icon's state for assistive tools while its panel is a flyout: expanded or not, and what it opens.
            expanded(el, key) {
                if (this.narrow && this.hasPanel(key)) {
                    el.setAttribute('aria-expanded', String(this.flyout === key));
                    el.setAttribute('aria-controls', 'v2-panel-' + key);
                } else {
                    el.removeAttribute('aria-expanded');
                    el.removeAttribute('aria-controls');
                }
            },
            openFlyout(key, opener) {
                this.flyout = key;
                this.opener = opener instanceof HTMLElement ? opener : null;
                this.$nextTick(() => this.$root.querySelector('[data-panel="' + key + '"] .v2-panel-body a, [data-panel="' + key + '"] .v2-panel-body input')?.focus());
            },
            railClick(e, key) {
                if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                const core = window.ElFrameCore;
                const home = e.currentTarget.getAttribute('href');
                const fold = core.railFlyout({ narrow: this.folded(), hasPanel: this.hasPanel(key), flyout: this.flyout, key, current: this.place });
                if (fold === 'open' || fold === 'close') {
                    e.preventDefault();
                    fold === 'open' ? this.openFlyout(key, e.currentTarget) : this.closeFlyout('toggle');
                    return;
                }
                const to = core.railTarget({ key, current: this.place, home, last: store.get(this.memory('last', key)), origin: location.origin });
                if (to.reset) store.drop(this.memory('last', key));
                e.preventDefault();
                if (fold === 'restart') {
                    // The place you are in, folded away: it starts over, and its flyout opens (or closes, when open).
                    const open = this.flyout !== key;
                    if (location.pathname + location.search + location.hash === to.go) {
                        open ? this.openFlyout(key, e.currentTarget) : this.closeFlyout('toggle');
                        return;
                    }
                    if (open) session.set('el-nav-flyout', key);
                }
                window.location.href = to.go;
            },
            togglePanel() {
                if (this.folded()) {
                    if (this.flyout !== null) {
                        this.closeFlyout('toggle');
                    } else if (this.hasPanel(this.place)) {
                        const active = document.activeElement;
                        this.openFlyout(this.place, active instanceof HTMLElement && active !== document.body ? active : this.$root.querySelector('[data-rail="' + this.place + '"]'));
                    }
                    return;
                }
                const sidebar = window.Alpine.store('sidebar');
                if (! this.hasPanel(this.place)) return;
                sidebar.isOpen ? sidebar.close() : sidebar.open();
                store.set(this.memory('panel', this.place), sidebar.isOpen ? 'open' : 'closed');
            },
            // A page's "Show groups" (Settings' home): the panel beside the page again, or its flyout.
            showPanel(e) {
                if (! this.hasPanel(this.place)) return;
                if (this.folded()) {
                    if (this.flyout !== this.place) this.openFlyout(this.place, e?.target instanceof HTMLElement ? e.target : null);
                    return;
                }
                const sidebar = window.Alpine.store('sidebar');
                if (! sidebar.isOpen) {
                    sidebar.open();
                    store.set(this.memory('panel', this.place), 'open');
                }
            },
            // how: 'escape', 'toggle' (⊟, ⌘\, its rail icon again), 'outside' (a click elsewhere) or 'chosen'.
            closeFlyout(how) {
                if (this.flyout === null) return;
                const key = this.flyout;
                const opener = this.opener;
                this.flyout = null;
                this.opener = null;
                this.$nextTick(() => {
                    const active = document.activeElement;
                    const panel = this.$root.querySelector('[data-panel="' + key + '"]');
                    // Focus is lost only on nothing, the body, the page's main or the flyout now hidden (ElFrameCore.focusLost);
                    // audio or video controls, a frame or an element a script focused keep it.
                    const lost = window.ElFrameCore.focusLost(active, document.body, panel);
                    if (! window.ElFrameCore.flyoutFocusBack(how, lost)) return;
                    const back = opener && opener.isConnected ? opener : this.$root.querySelector('[data-rail="' + key + '"]');
                    back?.focus();
                });
            },
        }));
        if (window.Alpine) register(); else document.addEventListener('alpine:init', register);

        // The frame's keys, on every page (ElFrameCore.frameKey).
        const mac = /mac|iphone|ipad/i.test(navigator.userAgentData?.platform || navigator.platform || '');
        // A dialog in front (Filament's, Help & shortcuts, an image viewer, the filters as a drawer): none of the keys.
        // A dialog kept in the page while closed (x-show) has no box, so only one on screen counts.
        const dialogOpen = () => Array.from(document.querySelectorAll('.fi-modal-open[aria-modal="true"], .el-help, [role="dialog"][aria-modal="true"]'))
            .some((el) => el.getClientRects().length > 0);
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
                mac, modal: dialogOpen(), rail: rail.length, items: Math.min(9, items.length),
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
