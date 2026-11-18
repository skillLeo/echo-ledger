{{--
    The v2.0 Library's keys and mouse (parity plan 3), only under ui_v2.library: J / K move through the list, Enter opens,
    X selects, Esc clears the selection; Ctrl/Cmd+A selects all; Delete (or Backspace) deletes the selection, with Undo
    in the notice; while something is selected a click on a row adds or takes it away and Shift-click takes the range;
    right-click opens the row's menu, or ⋮ of the selection when the row is part of it.
--}}
<script>
    (() => {
        if (window.elV2LibraryKeys) return;
        window.elV2LibraryKeys = true;
        const typing = (t) => t instanceof Element && (t.closest('input, textarea, select, [contenteditable="true"]') !== null);
        const library = () => {
            const list = document.querySelector('.v2-library');
            const host = list ? list.closest('[wire\\:id]') : null;
            return list && host && window.Livewire ? { list, wire: window.Livewire.find(host.getAttribute('wire:id')) } : null;
        };
        const rowOf = (el) => el.closest('.fi-ta-record, .fi-ta-row') || el;
        const boxOf = (el) => rowOf(el).querySelector('input.fi-ta-record-checkbox');
        const checked = (lib) => Array.from(lib.list.querySelectorAll('input.fi-ta-record-checkbox:checked'));
        const wide = () => window.matchMedia('(min-width: 1024px)').matches;
        const modal = () => document.querySelector('.fi-modal-open[aria-modal="true"]') !== null;
        document.addEventListener('keydown', (e) => {
            if (e.defaultPrevented || e.altKey || typing(e.target) || modal()) return;
            const lib = library();
            if (! lib) return;
            const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
            if ((e.ctrlKey || e.metaKey) && key === 'a') {
                const all = lib.list.querySelector('input.fi-ta-page-checkbox');
                if (all) { e.preventDefault(); if (! all.checked) all.click(); }
                return;
            }
            if (e.ctrlKey || e.metaKey) return;
            const items = Array.from(lib.list.querySelectorAll('[data-recording]'));
            let at = items.findIndex((el) => rowOf(el).classList.contains('el-kb-row'));
            if (key === 'j' || key === 'k') {
                if (! items.length) return;
                e.preventDefault();
                at = at < 0 ? 0 : Math.max(0, Math.min(items.length - 1, at + (key === 'j' ? 1 : -1)));
                items.forEach((el) => rowOf(el).classList.remove('el-kb-row'));
                rowOf(items[at]).classList.add('el-kb-row');
                rowOf(items[at]).scrollIntoView({ block: 'nearest' });
            } else if (key === 'Enter' && at >= 0) {
                e.preventDefault();
                const id = items[at].dataset.recording;
                if (wide()) lib.wire.selectRecording(id); else window.location.href = window.location.pathname.replace(/\/$/, '') + '/' + id;
            } else if (key === 'x' && at >= 0) {
                const box = boxOf(items[at]);
                if (box) { e.preventDefault(); box.click(); }
            } else if (key === 'Escape') {
                const on = checked(lib);
                if (on.length) { e.preventDefault(); on.forEach((b) => b.click()); }
            } else if ((key === 'Delete' || key === 'Backspace') && checked(lib).length) {
                const del = document.querySelector('[data-selection-delete]');
                if (del) { e.preventDefault(); del.click(); }
            }
        });
        // Selection mode: while something is selected a row click adds or takes away; Shift takes the range (the
        // table's own Shift-click on the checkbox).
        document.addEventListener('click', (e) => {
            const row = e.target instanceof Element ? e.target.closest('.v2-library [data-recording]') : null;
            const lib = library();
            if (! row || ! lib || e.button !== 0 || e.target.closest('button, input')) return;
            if (! checked(lib).length && ! e.shiftKey) return;
            const box = boxOf(row);
            if (! box) return;
            e.preventDefault();
            e.stopPropagation();
            box.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, shiftKey: e.shiftKey }));
        }, true);
        // Right-click: the row's menu, or ⋮ of the selection when the row is part of it.
        document.addEventListener('contextmenu', (e) => {
            const row = e.target instanceof Element ? e.target.closest('.v2-library [data-recording]') : null;
            const lib = library();
            if (! row || ! lib) return;
            const box = boxOf(row);
            const more = box && box.checked && checked(lib).length > 1
                ? document.querySelector('button[data-selection-more], [data-selection-more] button')
                : rowOf(row).querySelector('button.fi-ac-icon-btn-group');
            if (! more) return;
            e.preventDefault();
            // Filament's menus open on a left-button mousedown; on the next tick, as the right button's own events would
            // close a menu opened now.
            setTimeout(() => more.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, button: 0 })), 0);
        });
    })();
</script>
