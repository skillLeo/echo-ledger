{{--
    The v2.0 Library selection's page side (parity plan 3), only under ui_v2.library: which of Mark important and Unmark
    important ⋮ offers for the selected rows (resources/js/library-selection-core.cjs decides), and Copy for AI's last
    step, the text on the clipboard with its notice (the server writes the text: App\Filament\Support\V2CopyForAi).
--}}
<script>
    {!! file_get_contents(resource_path('js/library-selection-core.cjs')) !!}

    window.elV2Selection = window.elV2Selection || {
        /** For x-show on ⋮'s items: selected is the table's set of selected keys; tracking, a selection of every page. */
        shows(which, selected, tracking) {
            if (tracking) return true;
            const flags = [...(selected || [])].map((id) => {
                const row = document.querySelector('.v2-lib-row[data-recording="' + String(id).replace(/["\\]/g, '') + '"]');
                return row ? row.dataset.important : null;
            });
            return window.ElLibrarySelectionCore.importantShows(which, flags);
        },
        async copy(text, done, failed) {
            try {
                await navigator.clipboard.writeText(text);
                new FilamentNotification().title(done).success().send();
            } catch (e) {
                new FilamentNotification().title(failed).danger().send();
            }
        },
    };
</script>
