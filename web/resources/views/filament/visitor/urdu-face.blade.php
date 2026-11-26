{{--
    Signed out in Urdu (since v2.1): the console's Urdu face for Arabic script only (as filament.v2.head), so the sign-in
    page reads in Nastaliq without the new frame's stylesheet; Latin letters and digits keep the page's own face.
--}}
<style>
    @font-face { font-family: "Noto Nastaliq Urdu"; src: url("{{ asset('fonts/noto-nastaliq-urdu-arabic.woff2') }}") format("woff2"); font-weight: 400 700; font-display: swap; unicode-range: U+0600-06FF, U+0750-077F, U+0870-0891, U+0897-08E1, U+08E3-08FF, U+200C-200E, U+2010-2011, U+204F, U+2E41, U+FB50-FDFF, U+FE70-FE74, U+FE76-FEFC; }
    html[lang='ur'] body { font-family: "Noto Nastaliq Urdu", var(--font-family), ui-sans-serif, system-ui, sans-serif; line-height: 1.9; }
</style>
