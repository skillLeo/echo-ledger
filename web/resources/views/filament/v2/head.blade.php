{{--
    The v2.0 console's design values (tokens/ → resources/css/tokens.css, through resources/css/v2/console.css), only
    while a v2.0 part is on for the person. tokens.css follows data-theme on the root; Filament marks dark mode with
    the "dark" class, so the two are kept the same here, also when the theme changes on the page.
--}}
@vite('resources/css/v2/console.css')
<script>
    (() => {
        const root = document.documentElement;
        const sync = () => { root.dataset.theme = root.classList.contains('dark') ? 'dark' : 'light'; };
        sync();
        new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['class'] });
    })();
</script>
