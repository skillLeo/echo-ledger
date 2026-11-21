{{--
    The v2.0 Library's swipe at phone width (U9b, WEB-LIB-024), only under ui_v2.library: on a row, swipe left to Delete
    (the row's own Delete, which asks first) and right to Pin or Unpin; right to left mirrors both. The row follows the
    finger; resources/js/library-swipe-core.cjs decides. Nothing above 720 px, and keyboard and screen-reader users keep
    the row's menu and the recording's page.
--}}
<script>
    {!! file_get_contents(resource_path('js/library-swipe-core.cjs')) !!}

    (() => {
        if (window.elV2LibrarySwipe) return;
        window.elV2LibrarySwipe = true;
        const core = window.ElLibrarySwipeCore;
        let drag = null;
        const reset = (row) => { if (row) { row.style.transform = ''; row.style.transition = ''; } };

        document.addEventListener('pointerdown', (e) => {
            const row = e.target instanceof Element ? e.target.closest('.v2-lib-row') : null;
            // The row's own controls (the star) keep their taps; the row itself sits inside the record's link.
            const control = row && e.target.closest('button, a, input, [role="menu"]');
            if (! row || e.button !== 0 || ! core.enabled(window.innerWidth) || (control && row.contains(control))) return;
            row.style.touchAction = 'pan-y';
            drag = { row, x: e.clientX, y: e.clientY, dx: 0, dy: 0, swiping: false };
        });
        document.addEventListener('pointermove', (e) => {
            if (! drag) return;
            drag.dx = e.clientX - drag.x;
            drag.dy = e.clientY - drag.y;
            const intent = core.intent(drag.dx, drag.dy);
            if (intent === 'scroll' && ! drag.swiping) { drag = null; return; }
            if (intent === 'swipe') {
                drag.swiping = true;
                drag.row.style.transition = 'none';
                drag.row.style.transform = 'translateX(' + drag.dx + 'px)';
            }
        });
        const end = () => {
            if (! drag) return;
            const { row, dx, dy, swiping } = drag;
            drag = null;
            row.style.transition = '';
            reset(row);
            if (! swiping) return;
            // A swipe is not a click: the row does not open.
            row.addEventListener('click', (c) => { c.preventDefault(); c.stopImmediatePropagation(); }, { capture: true, once: true });
            const act = core.action(dx, dy, row.getBoundingClientRect().width, document.documentElement.dir === 'rtl');
            const id = row.dataset.recording;
            const host = row.closest('[wire\\:id]');
            const wire = host && window.Livewire ? window.Livewire.find(host.getAttribute('wire:id')) : null;
            if (! act || ! id || ! wire) return;
            if (act === 'pin') wire.togglePin(id);
            if (act === 'delete') wire.mountTableAction('delete', id);
        };
        document.addEventListener('pointerup', end);
        document.addEventListener('pointercancel', () => { if (drag) reset(drag.row); drag = null; });
    })();
</script>
