{{--
    Signed out (the login page and the other pages before signing in): this browser forgets where each place was left
    and whether its panel was folded (resources/js/frame-core.cjs allMemory), so none of it shows to the next person.
--}}
<script>
    {!! file_get_contents(resource_path('js/frame-core.cjs')) !!}
</script>
<script data-forget-places>
    (() => {
        try {
            window.ElFrameCore.allMemory(Object.keys(localStorage)).forEach((k) => localStorage.removeItem(k));
        } catch (e) { /* a private window keeps nothing */ }
    })();
</script>
