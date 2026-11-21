{{--
    The v2.0 Library selection's page side (parity plan 3), only under ui_v2.library: which of Mark important and Unmark
    important ⋮ offers for the selected rows (resources/js/library-selection-core.cjs decides), and Copy for AI's last
    step, the text on the clipboard with its notice (the server writes the text: App\Filament\Support\V2CopyForAi). A browser
    that refuses a copy made after the round trip (Safari) gets the text with its own Copy button instead.
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
                // Safari (and any browser that wants the copy inside the click itself) refuses once the text came back
                // from the server: the text is shown with its own Copy button, whose click may write it.
                this.offer(text, done, failed);
            }
        },
        offer(text, done, failed) {
            const box = this.offerBox();
            if (! box || typeof box.showModal !== 'function') {
                new FilamentNotification().title(failed).danger().send();
                return;
            }
            box.querySelector('textarea').value = text;
            box.querySelector('[data-copy-now]').onclick = async () => {
                try {
                    await navigator.clipboard.writeText(text);
                    box.close();
                    new FilamentNotification().title(done).success().send();
                } catch (e) {
                    // Still refused: the text stays selected for Ctrl / Cmd + C.
                    box.querySelector('textarea').select();
                    new FilamentNotification().title(failed).danger().send();
                }
            };
            box.showModal();
            box.querySelector('textarea').select();
        },
        /** The window that holds the text, made the first time it is needed (the page head can't hold it). */
        offerBox() {
            let box = document.getElementById('v2-copy-offer');
            if (box || ! document.body) return box;
            const words = @js(['title' => __('Copy for AI'), 'why' => __('Your browser did not let the page copy by itself. Press Copy.'), 'copy' => __('Copy'), 'close' => __('Close')]);
            box = document.createElement('dialog');
            box.id = 'v2-copy-offer';
            box.className = 'v2-card';
            box.setAttribute('aria-labelledby', 'v2-copy-offer-title');
            box.innerHTML = '<form method="dialog" class="v2-stack"><h2 id="v2-copy-offer-title" class="v2-group-title"></h2><p class="v2-help"></p>'
                + '<textarea class="v2-input" rows="8" readonly></textarea><div class="v2-form-foot">'
                + '<button type="submit" class="v2-btn v2-btn-secondary"></button><button type="button" class="v2-btn v2-btn-primary" data-copy-now></button></div></form>';
            box.querySelector('h2').textContent = words.title;
            box.querySelector('p').textContent = words.why;
            box.querySelector('textarea').setAttribute('aria-label', words.title);
            box.querySelector('[type="submit"]').textContent = words.close;
            box.querySelector('[data-copy-now]').textContent = words.copy;
            document.body.appendChild(box);
            return box;
        },
    };
</script>
