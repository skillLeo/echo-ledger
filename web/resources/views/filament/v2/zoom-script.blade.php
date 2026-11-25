{{-- UX-07 and UX-18 (v21_zoom): the page's zoom, kept in this browser and applied before the first paint; the account
     menu's Zoom row steps it through window.ElZoom (resources/js/zoom-core.cjs decides, tested under Node). --}}
<script>
    {!! file_get_contents(resource_path('js/zoom-core.cjs')) !!}
    window.ElZoom = window.ElZoomCore.install(document, window);
</script>
