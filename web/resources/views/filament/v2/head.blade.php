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
    // The new frame's sidebar: the classic shell's (its width, the rail, what it remembers), with the edge that resizes
    // it following the reading direction (right to left in Urdu the sidebar is on the right, so dragging left widens it).
    window.elV2Sidebar = window.elV2Sidebar || ((config) => {
        const base = window.elShellSidebar(config);
        const sign = () => (document.documentElement.dir === 'rtl' ? -1 : 1);
        return {
            ...base,
            startResize(e) {
                if (e.button !== 0) return;
                e.preventDefault();
                const startX = e.clientX;
                const start = this.width;
                const move = (m) => this.setWidth(start + sign() * (m.clientX - startX), false);
                const up = () => {
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', up);
                    document.body.classList.remove('el-resizing');
                    this.setWidth(this.width, true);
                };
                document.body.classList.add('el-resizing');
                window.addEventListener('pointermove', move);
                window.addEventListener('pointerup', up);
            },
            resizeKey(e) {
                const step = (e.shiftKey ? 40 : 16) * sign();
                if (e.key === 'ArrowLeft') this.setWidth(this.width - step, true);
                else if (e.key === 'ArrowRight') this.setWidth(this.width + step, true);
                else if (e.key === 'Home') this.setWidth(config.min, true);
                else if (e.key === 'End') this.setWidth(config.max, true);
                else return;
                e.preventDefault();
            },
        };
    });
</script>
@includeWhen(($sheet ?? 'console') === 'console' && \App\Filament\Support\ConsoleV2::library(), 'filament.v2.library-keys')
@includeWhen(($sheet ?? 'console') === 'console' && \App\Filament\Support\ConsoleV2::library(), 'filament.v2.copy')
@includeWhen(($sheet ?? 'console') === 'console' && \App\Filament\Support\ConsoleV2::library(), 'filament.v2.library-selection')
@includeWhen(($sheet ?? 'console') === 'console' && \App\Filament\Support\ConsoleV2::library(), 'filament.v2.library-swipe')
