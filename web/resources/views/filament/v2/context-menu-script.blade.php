{{--
    UX-22 (PLAN_V2_1_POLISH 3b) on the web console, only under ui_v2.library: a Library row's right-click menu, in
    Windows 11's style (8 px corners, 32 px rows, 16 px glyphs, light and dark), the desktop's on Windows. A right-click
    on a row opens it at the pointer, and Shift+F10 or the Menu key opens it for the row in hand (the focused one, else
    the one J / K is on). A row that is part of the selection opens the menu of the selection, titled "3 recordings".
    Only what fits is in it (resources/js/context-menu-core.cjs decides, from the page's
    filament.v2.context-menu); sub-menus are one level deep. Arrows, Home and End move, Enter or Space picks, → opens
    a sub-menu and ← closes it (the other way round right to left), Esc and Tab close. A pick goes to the page
    (ListCaptureSessions::menuAction), Open as the row's own click. Everywhere else the browser keeps its own menu.
--}}
<script>
    {!! file_get_contents(resource_path('js/context-menu-core.cjs')) !!}

    (() => {
        if (window.elV2ContextMenu) return;
        const core = window.ElContextMenuCore;
        const rtl = () => document.documentElement.dir === 'rtl';
        const dataOf = () => {
            const el = document.querySelector('[data-v2-menu-data]');
            try { return el ? JSON.parse(el.textContent) : null; } catch (e) { return null; }
        };
        const glyph = (name) => {
            const t = name ? document.querySelector('[data-v2-menu-icons] template[data-icon="' + String(name).replace(/["\\]/g, '') + '"]') : null;
            return t ? t.content.cloneNode(true) : null;
        };
        const rowOf = (el) => ({
            id: el.dataset.recording, pinned: el.dataset.pinned === '1', important: el.dataset.important === '1', opened: el.dataset.opened !== '0',
            state: el.dataset.state || 'ready', client: el.dataset.client || null,
            labels: (() => { try { return JSON.parse(el.dataset.labels || '[]'); } catch (e) { return []; } })(),
        });
        // The menu now open: its lists (the menu, then its open sub-menu), what it was opened on, and where focus goes back.
        let open = null;

        function close(back = true) {
            if (! open) return;
            const was = open;
            open = null;
            was.layer.remove();
            window.removeEventListener('resize', onAway, true);
            window.removeEventListener('blur', onAway);
            document.removeEventListener('scroll', onAway, true);
            document.removeEventListener('pointerdown', onPointer, true);
            if (back && was.back && was.back.isConnected) was.back.focus({ preventScroll: true });
        }
        const onAway = () => close(false);
        const onPointer = (e) => { if (open && ! open.layer.contains(e.target)) close(false); };

        const itemsOf = (list) => Array.from(list.querySelectorAll(':scope > [role^="menuitem"]'));
        function focusAt(list, at) {
            const items = itemsOf(list);
            if (items[at]) items[at].focus({ preventScroll: true });
        }
        function closeSub() {
            if (open && open.sub) {
                const parent = open.sub.parentItem;
                open.sub.remove();
                open.sub = null;
                parent.setAttribute('aria-expanded', 'false');
                return parent;
            }
            return null;
        }

        /** One list of items (the menu, or a sub-menu beside its item). */
        function list(items, label, title) {
            const ul = document.createElement('div');
            ul.className = 'v2-ctx';
            ul.setAttribute('role', 'menu');
            ul.setAttribute('aria-label', title || label);
            ul.setAttribute('aria-orientation', 'vertical');
            ul.tabIndex = -1;
            const line = () => {
                const sep = document.createElement('div');
                sep.className = 'v2-ctx-sep';
                sep.setAttribute('role', 'separator');
                ul.append(sep);
            };
            // Several: a greyed title line ("3 recordings") and a hairline, as the desktop's.
            if (title) {
                const head = document.createElement('div');
                head.className = 'v2-ctx-title';
                head.setAttribute('role', 'presentation');
                head.textContent = title;
                ul.append(head);
                line();
            }
            items.forEach((item, at) => {
                if (item.separator && at > 0) line();
                const row = document.createElement('div');
                const checkable = item.checked !== undefined;
                row.className = 'v2-ctx-item' + (item.danger ? ' is-danger' : '');
                row.setAttribute('role', checkable && ! item.items ? 'menuitemcheckbox' : 'menuitem');
                if (checkable && ! item.items) row.setAttribute('aria-checked', item.checked ? 'true' : 'false');
                row.tabIndex = -1;
                row.dataset.action = item.action;
                if (item.choice !== undefined) row.dataset.choice = item.choice;
                const icon = document.createElement('span');
                icon.className = 'v2-ctx-icon';
                const shown = item.checked ? glyph('check') : glyph(item.icon);
                if (shown) icon.append(shown);
                const words = document.createElement('span');
                words.className = 'v2-ctx-label';
                words.dir = 'auto';
                words.textContent = item.label;
                row.append(icon, words);
                if (item.items) {
                    row.setAttribute('aria-haspopup', 'menu');
                    row.setAttribute('aria-expanded', 'false');
                    const more = document.createElement('span');
                    more.className = 'v2-ctx-more';
                    const chevron = glyph('more');
                    if (chevron) more.append(chevron);
                    row.append(more);
                }
                row.elItem = item;
                ul.append(row);
            });
            return ul;
        }

        /** At x, y: its start there (its right edge, right to left), never off the window. */
        function placeAt(el, x, y, start = true) {
            const box = el.getBoundingClientRect();
            const at = core.place(start && rtl() ? x - box.width : x, y, box.width, box.height, window.innerWidth, window.innerHeight);
            el.style.left = at.x + 'px';
            el.style.top = at.y + 'px';
        }

        function openSub(row, focusFirst) {
            if (open.sub && open.sub.parentItem === row) {
                if (focusFirst) focusAt(open.sub, 0);
                return;
            }
            closeSub();
            const sub = list(row.elItem.items, row.elItem.label, null);
            sub.parentItem = row;
            sub.classList.add('is-sub');
            open.layer.append(sub);
            open.sub = sub;
            row.setAttribute('aria-expanded', 'true');
            // Beside its item, on the reading side, turned over when there is no room; never off the window.
            const r = row.getBoundingClientRect();
            const w = sub.getBoundingClientRect().width;
            const after = rtl() ? r.left - w + 4 : r.right - 4;
            const room = rtl() ? after >= 0 : after + w <= window.innerWidth;
            placeAt(sub, room ? after : (rtl() ? r.right - 4 : r.left - w + 4), r.top - 4, false);
            if (focusFirst) focusAt(sub, 0);
        }

        function pick(row) {
            const item = row.elItem;
            if (! item) return;
            if (item.items) { openSub(row, true); return; }
            const was = open;
            close(true);
            const ids = was.targets;
            if (item.action === 'recording.open') {
                if (window.matchMedia('(min-width: 1024px)').matches && typeof was.wire.selectRecording === 'function') was.wire.selectRecording(ids[0]);
                else window.location.href = window.location.pathname.replace(/\/$/, '') + '/' + encodeURIComponent(ids[0]);
                return;
            }
            was.wire.menuAction(item.action, ids, item.choice === undefined ? null : item.choice);
        }

        function onKey(e) {
            if (! open) return;
            const inSub = open.sub && open.sub.contains(e.target);
            const menu = inSub ? open.sub : open.menu;
            const items = itemsOf(menu);
            const at = items.indexOf(e.target);
            const forward = rtl() ? 'ArrowLeft' : 'ArrowRight';
            const backward = rtl() ? 'ArrowRight' : 'ArrowLeft';
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
                e.preventDefault();
                focusAt(menu, core.move(items.length, at, e.key));
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (items[at]) pick(items[at]);
            } else if (e.key === forward && items[at] && items[at].elItem.items) {
                e.preventDefault();
                openSub(items[at], true);
            } else if ((e.key === backward || e.key === 'Escape') && inSub) {
                e.preventDefault();
                const parent = closeSub();
                if (parent) parent.focus({ preventScroll: true });
            } else if (e.key === 'Escape' || e.key === 'Tab') {
                e.preventDefault();
                close(true);
            }
            e.stopPropagation();
        }

        /**
         * Opens the menu for rows (the .v2-lib-row elements) at x, y; false when it has nothing to show or the page has
         * no menu (the caller keeps the browser's). From the keyboard its first item has the focus; from the pointer the
         * menu has it, and nothing is lit until the pointer or an arrow moves.
         */
        function openFor(rows, at, wire, byKeyboard = false) {
            const data = dataOf();
            if (! data || ! wire || ! rows.length) return false;
            const made = core.recordingMenu(data, rows.map(rowOf));
            if (! made.items.length) return false;
            const back = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
            close(false);
            const layer = document.createElement('div');
            layer.className = 'v2-ctx-layer';
            const menu = list(made.items, (data.words || {}).menu || 'Recording actions', made.title);
            layer.append(menu);
            document.body.append(layer);
            open = { layer, menu, sub: null, targets: made.targets, wire, back };
            placeAt(menu, at.x, at.y);
            layer.addEventListener('keydown', onKey);
            layer.addEventListener('click', (e) => {
                const row = e.target instanceof Element ? e.target.closest('.v2-ctx-item') : null;
                if (row) pick(row);
            });
            layer.addEventListener('pointerover', (e) => {
                const row = e.target instanceof Element ? e.target.closest('.v2-ctx-item') : null;
                if (! row) return;
                row.focus({ preventScroll: true });
                if (! open.sub || ! open.sub.contains(row)) {
                    if (row.elItem.items) openSub(row, false);
                    else if (open.menu.contains(row)) closeSub();
                }
            });
            layer.addEventListener('contextmenu', (e) => e.preventDefault());
            window.addEventListener('resize', onAway, true);
            window.addEventListener('blur', onAway);
            document.addEventListener('scroll', onAway, true);
            document.addEventListener('pointerdown', onPointer, true);
            if (byKeyboard) focusAt(menu, 0);
            else menu.focus({ preventScroll: true });
            return true;
        }

        // Shift+F10 and the Menu key: the menu of the row in hand, at its start. The browser's own menu, which the
        // Menu key opens too, is kept away while ours is open.
        let byKey = 0;
        document.addEventListener('keydown', (e) => {
            if (! (e.key === 'ContextMenu' || (e.key === 'F10' && e.shiftKey)) || e.ctrlKey || e.metaKey || e.altKey) return;
            const lib = document.querySelector('.v2-library');
            const host = lib ? lib.closest('[wire\\:id]') : null;
            if (! lib || ! host || ! window.Livewire) return;
            const focused = document.activeElement instanceof Element ? document.activeElement.closest('.v2-library [data-recording]') : null;
            const current = lib.querySelector('.el-kb-row [data-recording]');
            const row = focused || current;
            if (! row) return;
            const rows = window.elV2ContextMenu.rowsFor(row);
            const r = row.getBoundingClientRect();
            if (openFor(rows, { x: rtl() ? r.right - 24 : r.left + 24, y: r.top + r.height / 2 }, window.Livewire.find(host.getAttribute('wire:id')), true)) {
                e.preventDefault();
                byKey = Date.now();
            }
        });
        document.addEventListener('contextmenu', (e) => {
            if (open && Date.now() - byKey < 1000) e.preventDefault();
        }, true);

        window.elV2ContextMenu = {
            openFor,
            close,
            /** What a row's menu is for: the selection when the row is part of it (two or more), else the row. */
            rowsFor(row) {
                const box = (el) => (el.closest('.fi-ta-record, .fi-ta-row') || el).querySelector('input.fi-ta-record-checkbox');
                const mine = box(row);
                if (! mine || ! mine.checked) return [row];
                const all = Array.from(document.querySelectorAll('.v2-library input.fi-ta-record-checkbox:checked'))
                    .map((b) => (b.closest('.fi-ta-record, .fi-ta-row') || b).querySelector('[data-recording]')).filter(Boolean);
                return all.length > 1 ? all : [row];
            },
        };
    })();
</script>
