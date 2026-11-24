{{--
    UX-25 (PLAN_V2_1_POLISH, an owner request), only under ui_v2.library: the Library keeps your place, and Back to top.
    - The Library notes where it is as it scrolls (its address with the view, tab, filters and search; the row at the top
      of the window and where it stood; how many rows Load more showed). A recording's page notes that it was opened, and
      its "Library" link goes back to the list as it was left.
    - Coming back from a recording (the browser's Back, that link, the rail), the list shows as many rows again, scrolls
      to where it was, and the recording you opened keeps a light grey tint and the keyboard's place (J / K go on from
      it, unless the person has taken the focus meanwhile) for a minute, until another row is opened or clicked, or the view, tab, filters or search change. Not the
      selection's colour: it never looks like a ticked row.
    - Back to top: a small round ↑ at the bottom corner once a long list (the Library, Activity, Shared with me,
      Recently deleted) is more than a screen down; it scrolls to the top and the first row takes the focus. Home,
      Ctrl+Home and ⌘↑ on a Mac do the same.
    resources/js/keep-place-core.cjs decides.
--}}
<template id="el-back-top">
    <button type="button" class="v2-back-top" data-back-top aria-label="{{ __('Back to top') }}" title="{{ __('Back to top') }}"><x-el.v2.icon name="arrow-up" /></button>
</template>
<script>
    {!! file_get_contents(resource_path('js/keep-place-core.cjs')) !!}

    (() => {
        if (window.elV2KeepPlace) return;
        window.elV2KeepPlace = true;
        const core = window.ElKeepPlaceCore;
        // One person's place in one workspace (NavFrame::memoryScope): another workspace's list never comes back to it.
        const KEYS = core.keys({!! json_encode(\App\Filament\Support\NavFrame::memoryScope(), JSON_HEX_TAG) !!});
        // The long lists that have Back to top: the Library, Activity, Shared with me and Recently deleted.
        const LISTS = {!! json_encode(array_map(fn (string $url): string => rtrim((string) parse_url($url, PHP_URL_PATH), '/'), [
            \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('index'), \App\Filament\Pages\Activity::getUrl(),
            \App\Filament\Pages\SharedWithMe::getUrl(), \App\Filament\Pages\RecentlyDeleted::getUrl(),
        ]), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
        const store = {
            get(key) { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) { return null; } },
            set(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* a private window */ } },
        };
        const here = () => location.pathname + location.search;
        const library = () => document.querySelector('.v2-library');
        const rows = () => Array.from(document.querySelectorAll('.v2-library .v2-lib-row[data-recording]'));
        const recordOf = (row) => row.closest('.fi-ta-record, .fi-ta-row') || row;
        const linkOf = (row) => recordOf(row).querySelector('a[href]') || row;
        const smooth = () => (window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth');

        // A recording's page: it was opened (the Library tints it on the way back), and its "Library" link goes back to
        // the list as it was left (the same view, tab, filters and search). Any other page that is not the Library: the
        // way back from a recording is over (Library, a recording, Activity, Library starts the list at its top).
        const recordingPage = () => {
            const page = document.querySelector('[data-recording-page]');
            if (! page) {
                if (! library()) store.set(KEYS.opened, null);
                return;
            }
            store.set(KEYS.opened, { id: page.dataset.recordingPage, at: Date.now() });
            const saved = store.get(KEYS.place);
            if (saved && typeof saved.url === 'string' && saved.url.split('?')[0] === location.pathname.replace(/\/[^/]+$/, '')) {
                document.querySelectorAll('a.v2-back').forEach((a) => { a.href = saved.url; });
            }
        };

        // The Library: where it is, as it scrolls and as it is left.
        let restoring = false;
        const note = () => {
            if (! library() || restoring) return;
            const all = rows();
            const top = all.find((r) => r.getBoundingClientRect().bottom > 0);
            store.set(KEYS.place, core.place({ url: here(), y: window.scrollY, id: top ? top.dataset.recording : null,
                offset: top ? top.getBoundingClientRect().top : 0, shown: all.length, at: Date.now() }));
        };
        let noting = 0;
        window.addEventListener('scroll', () => { window.clearTimeout(noting); noting = window.setTimeout(note, 150); }, { passive: true });
        window.addEventListener('pagehide', note);

        // Anything the person does from the moment the page loads (a click anywhere, even where nothing takes the focus, as
        // Safari's buttons and boxes; the wheel; a touch; a key) is theirs: coming back then leaves the list where they
        // put it, untinted, and the focus theirs. Heard first (the capture phase), before anything else can stop it.
        let taken = false;
        const INTERACTIONS = ['pointerdown', 'wheel', 'touchstart', 'keydown'];
        INTERACTIONS.forEach((type) => window.addEventListener(type, () => { taken = true; }, { capture: true, passive: true }));

        // The tint: on the opened row while it lasts; Livewire's redraws keep it (they would take a class they don't know).
        let tint = null;
        const paint = () => {
            document.querySelectorAll('.v2-library .is-last-opened').forEach((el) => el.classList.remove('is-last-opened'));
            if (! tint || ! core.tinted(tint.since, Date.now()) || here() !== tint.url) { tint = null; return; }
            const row = document.querySelector('.v2-library .v2-lib-row[data-recording="' + tint.id.replace(/["\\]/g, '') + '"]');
            if (row) recordOf(row).classList.add('is-last-opened');
        };
        document.addEventListener('click', (e) => {
            if (tint && e.target instanceof Element && e.target.closest('.v2-library .fi-ta-record, .v2-library .fi-ta-row')) { tint = null; paint(); }
        }, true);

        async function restore() {
            const lib = library();
            const saved = store.get(KEYS.place);
            const opened = store.get(KEYS.opened);
            if (! lib || ! core.restorable(saved, here(), Date.now()) || ! core.cameBack(saved, opened)) return;
            const host = lib.closest('[wire\\:id]');
            const wire = host && window.Livewire ? window.Livewire.find(host.getAttribute('wire:id')) : null;
            // Where the focus is now: if the person takes it meanwhile (the search, a filter), the row never takes it back.
            const focusAtStart = document.activeElement;
            restoring = true;
            try {
                // The rows load after the page (the table's own deferred load): wait for them, up to a few seconds.
                for (let i = 0; i < 50 && ! rows().length; i++) await new Promise((r) => setTimeout(r, 100));
                // As many rows as were shown, in one request (never past the list's limit).
                const upTo = wire && typeof wire.v2ShowUpTo === 'function' ? core.showUpTo(saved.shown, rows().length, {{ \App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions::V2_MAX }}) : 0;
                if (upTo) {
                    await wire.v2ShowUpTo(upTo);
                    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
                }
                // The person searched or filtered meanwhile: that is another list now.
                if (here() !== saved.url) return;
                const steps = core.restoreSteps(taken, core.focusFree(document.activeElement, focusAtStart, document.body));
                const anchor = saved.id ? document.querySelector('.v2-library .v2-lib-row[data-recording="' + saved.id.replace(/["\\]/g, '') + '"]') : null;
                const maxY = document.documentElement.scrollHeight - window.innerHeight;
                if (steps.scroll) window.scrollTo({ top: core.scrollTo(saved, { rowTop: anchor ? anchor.getBoundingClientRect().top + window.scrollY : null, maxY }), behavior: 'auto' });
                const row = document.querySelector('.v2-library .v2-lib-row[data-recording="' + opened.id.replace(/["\\]/g, '') + '"]');
                if (row && steps.tint) {
                    tint = { id: opened.id, url: here(), since: Date.now() };
                    paint();
                    window.setTimeout(paint, core.TINT + 50);
                    // The keyboard's place: J / K go on from it, and the focus is on it, unless the person has taken the
                    // focus meanwhile (typing in the search: their letters stay theirs, never J / K / X).
                    if (steps.focus) {
                        rows().forEach((r) => recordOf(r).classList.remove('el-kb-row'));
                        recordOf(row).classList.add('el-kb-row');
                        row.scrollIntoView({ block: 'nearest' });
                        linkOf(row).focus({ preventScroll: true });
                    }
                }
            } finally {
                restoring = false;
                store.set(KEYS.opened, null);
                note();
            }
        }

        // Back to top, on the four long lists only: the Library, Activity, Shared with me, Recently deleted.
        const longList = () => (LISTS.includes(location.pathname.replace(/\/$/, '')) ? document.querySelector('.v2-library, .fi-main .el-rows, .fi-main .fi-ta-content') : null);
        const firstRow = () => {
            const list = longList();
            const first = list ? list.querySelector('.v2-lib-row[data-recording], :scope > li, .fi-ta-record') : null;
            return first ? (first.matches('.v2-lib-row') ? linkOf(first) : first.querySelector('a[href], button')) : null;
        };
        const toTop = () => {
            window.scrollTo({ top: 0, behavior: smooth() });
            const first = firstRow();
            if (first) first.focus({ preventScroll: true });
        };
        const button = () => document.querySelector('[data-back-top]');
        const showButton = () => {
            const b = button();
            if (b) b.classList.toggle('is-shown', !! longList() && core.showsBackToTop(window.scrollY, window.innerHeight));
        };
        window.addEventListener('scroll', showButton, { passive: true });
        document.addEventListener('click', (e) => {
            if (e.target instanceof Element && e.target.closest('[data-back-top]')) { e.preventDefault(); toTop(); }
        });
        document.addEventListener('keydown', (e) => {
            const t = e.target;
            // A field, and a control with its own Home (a menu, a list box, a dialog, a toolbar, tabs, a slider).
            const typing = t instanceof Element && t.closest('input, textarea, select, [contenteditable="true"], [role="menu"], [role="listbox"], [role="dialog"], [role="toolbar"], [role="tablist"], [role="slider"]') !== null;
            if (! longList() || e.defaultPrevented || ! core.toTopKey({ key: e.key, ctrlKey: e.ctrlKey, metaKey: e.metaKey, altKey: e.altKey, shiftKey: e.shiftKey, typing }, /Mac/i.test(navigator.platform))) return;
            e.preventDefault();
            toTop();
        });

        const start = () => {
            if (longList() && ! button()) {
                const t = document.getElementById('el-back-top');
                if (t) document.body.append(t.content.cloneNode(true));
            }
            showButton();
            if (window.Livewire && typeof window.Livewire.hook === 'function') window.Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(() => { paint(); showButton(); })));
            void restore();
        };
        document.addEventListener('DOMContentLoaded', recordingPage, { once: true });
        document.addEventListener('livewire:initialized', start, { once: true });
        // Back to a page the browser kept as it was (its back-forward cache): the place is there; the tint comes back.
        window.addEventListener('pageshow', (e) => { if (e.persisted) { taken = false; recordingPage(); void restore(); } });
    })();
</script>
