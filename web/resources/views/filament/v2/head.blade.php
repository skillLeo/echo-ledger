{{--
    The v2.0 design values (tokens/ → resources/css/tokens.css): resources/css/v2/console.css for the console while a
    v2.0 part is on for the person, admin.css for the admin panel under ui_v2.admin. tokens.css follows data-theme on the root; Filament marks dark mode with
    the "dark" class, so the two are kept the same here, also when the theme changes on the page.
--}}
@vite('resources/css/v2/'.($sheet ?? 'console').'.css')
{{--
    The Urdu face for Arabic script only (as the meeting pack's ARABIC_SCRIPT_RANGE): Latin letters and digits in an
    Urdu page fall through to the console's own face, instead of a Nastaliq font installed on the computer.
--}}
<style>
    @font-face { font-family: "Noto Nastaliq Urdu"; src: url("{{ asset('fonts/noto-nastaliq-urdu-arabic.woff2') }}") format("woff2"); font-weight: 400 700; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
</style>
<script>
    (() => {
        const root = document.documentElement;
        const sync = () => { root.dataset.theme = root.classList.contains('dark') ? 'dark' : 'light'; };
        sync();
        new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['class'] });
    })();
</script>
@includeWhen(($sheet ?? 'console') === 'console' && \App\Filament\Support\ConsoleV2::library(), 'filament.v2.library-keys')
