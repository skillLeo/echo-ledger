{{--
    The v2.0 Library's keys and mouse (parity plan 3), only under ui_v2.library: J / K move through the list, Enter opens,
    X selects, Esc clears the selection; Ctrl/Cmd+A selects all; Delete (or Backspace) deletes the selection, with Undo
    in the notice; while something is selected a click on a row adds or takes it away and Shift-click takes the range;
    right-click opens the row's menu, or the selection's when the row is part of it (UX-22, filament.v2.context-menu-script).
--}}
<script>
    {!! file_get_contents(resource_path('js/library-keys-core.cjs')) !!}

    (() => {
        if (window.elV2LibraryKeys) return;
        window.elV2LibraryKeys = true;
        const core = window.ElLibraryKeysCore;
        const field = (t) => t instanceof Element && t.closest('input, textarea, select, [contenteditable="true"]') !== null ? { typing: true }
            : (t instanceof Element && t.closest('button, a[href], summary, [role="button"], [role="menuitem"]') !== null ? { control: true } : t);
        const library = () => {
            const list = document.querySelector('.v2-library');
            const host = list ? list.closest('[wire\\:id]') : null;
            return list && host && window.Livewire ? { list, wire: window.Livewire.find(host.getAttribute('wire:id')) } : null;
        };
        const rowOf = (el) => el.closest('.fi-ta-record, .fi-ta-row') || el;
        const boxOf = (el) => rowOf(el).querySelector('input.fi-ta-record-checkbox');
        const checked = (lib) => Array.from(lib.list.querySelectorAll('input.fi-ta-record-checkbox:checked'));
        // What the table holds selected (its own count, which reaches past this page after "Select all N").
        const selected = (lib) => {
            const table = lib.list.closest('.fi-ta') || document.querySelector('.fi-ta');
            const data = table && window.Alpine ? window.Alpine.$data(table) : null;
            return data && typeof data.getSelectedRecordsCount === 'function' ? data.getSelectedRecordsCount() : checked(lib).length;
        };
        const wide = () => window.matchMedia('(min-width: 1024px)').matches;
        const modal = () => document.querySelector('.fi-modal-open[aria-modal="true"]') !== null;
        document.addEventListener('keydown', (e) => {
            const lib = library();
            if (! lib) return;
            const items = Array.from(lib.list.querySelectorAll('[data-recording]'));
            const all = lib.list.querySelector('input.fi-ta-page-checkbox');
            const on = checked(lib);
            const act = core.keyAction({ key: e.key, ctrlKey: e.ctrlKey, metaKey: e.metaKey, altKey: e.altKey, defaultPrevented: e.defaultPrevented, target: field(e.target) }, {
                modal: modal(), items: items.length, at: items.findIndex((el) => rowOf(el).classList.contains('el-kb-row')),
                checked: on.length, selected: selected(lib), allChecked: !! (all && all.checked),
            });
            if (! act) return;
            if (act.do === 'none') { e.preventDefault(); return; }
            if (act.do === 'select-all') {
                if (all) { e.preventDefault(); all.click(); }
            } else if (act.do === 'move') {
                e.preventDefault();
                items.forEach((el) => rowOf(el).classList.remove('el-kb-row'));
                rowOf(items[act.at]).classList.add('el-kb-row');
                rowOf(items[act.at]).scrollIntoView({ block: 'nearest' });
            } else if (act.do === 'open') {
                e.preventDefault();
                const id = items[act.at].dataset.recording;
                if (wide()) lib.wire.selectRecording(id); else window.location.href = window.location.pathname.replace(/\/$/, '') + '/' + id;
            } else if (act.do === 'toggle') {
                const box = boxOf(items[act.at]);
                if (box) { e.preventDefault(); box.click(); }
            } else if (act.do === 'clear') {
                e.preventDefault();
                on.forEach((b) => b.click());
            } else if (act.do === 'delete') {
                const del = document.querySelector('.v2-sel-actions [data-selection-delete]') || document.querySelector('[data-selection-delete]');
                if (del) { e.preventDefault(); del.click(); }
            }
        });
        // Selection mode: while something is selected a row click adds or takes away; Shift takes the range (the
        // table's own Shift-click on the checkbox).
        document.addEventListener('click', (e) => {
            const row = e.target instanceof Element ? e.target.closest('.v2-library [data-recording]') : null;
            const lib = library();
            if (! lib) return;
            const act = core.clickAction({ button: e.button, shiftKey: e.shiftKey, target: field(e.target) }, {
                inRow: !! row, onControl: !! (row && e.target.closest('button, input')), checked: checked(lib).length,
            });
            const box = act && boxOf(row);
            if (! box) return;
            e.preventDefault();
            e.stopPropagation();
            box.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: e.shiftKey }));
        }, true);
        // Right-click: the row's menu, or the selection's when the row is part of it (UX-22: ours, filament.v2.
        // context-menu-script); ⋮ of the selection while it is every page's (its rows are not all here), and the row's
        // ⋯ where the page has no menu of its own.
        document.addEventListener('contextmenu', (e) => {
            const row = e.target instanceof Element ? e.target.closest('.v2-library [data-recording]') : null;
            const lib = library();
            if (! lib) return;
            const box = row && boxOf(row);
            const act = core.contextAction({ target: field(e.target) }, { inRow: !! row, rowChecked: !! (box && box.checked), checked: checked(lib).length });
            const table = lib.list.closest('.fi-ta') || document.querySelector('.fi-ta');
            const data = table && window.Alpine ? window.Alpine.$data(table) : null;
            const everyPage = !! (data && data.isTrackingDeselectedRecords);
            const menu = window.elV2ContextMenu;
            if (act && menu && ! (act === 'selection-menu' && everyPage)
                && menu.openFor(act === 'selection-menu' ? menu.rowsFor(row) : [row], { x: e.clientX, y: e.clientY }, lib.wire, false, act === 'selection-menu')) {
                e.preventDefault();
                return;
            }
            const more = act === 'selection-menu' ? (document.querySelector('.v2-sel-actions button[data-selection-more], .v2-sel-actions [data-selection-more] button') || document.querySelector('button[data-selection-more], [data-selection-more] button'))
                : (act === 'row-menu' ? rowOf(row).querySelector('button.fi-ac-icon-btn-group') : null);
            if (! more) return;
            e.preventDefault();
            // Filament's menus open on a left-button mousedown; on the next tick, as the right button's own events would
            // close a menu opened now.
            setTimeout(() => more.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 })), 0);
        });
    })();
</script>
