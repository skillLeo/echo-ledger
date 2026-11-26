{{--
    Signed out (the login page and the other pages before signing in): this browser forgets where each place was left
    and whether its panel was folded (resources/js/frame-core.cjs allMemory), and where this tab left the Library (UX-25),
    so none of it shows to the next person.
--}}
<script>
    {!! file_get_contents(resource_path('js/frame-core.cjs')) !!}
</script>
<script data-forget-places>
    (() => {
        try {
            window.ElFrameCore.allMemory(Object.keys(localStorage)).forEach((k) => localStorage.removeItem(k));
            // UX-25: where the Library was left in this tab, and the recording last opened.
            Object.keys(sessionStorage).filter((k) => k.startsWith('el-place:')).forEach((k) => sessionStorage.removeItem(k));
        } catch (e) { /* a private window keeps nothing */ }
    })();
</script>
