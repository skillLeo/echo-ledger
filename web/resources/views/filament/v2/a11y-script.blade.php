{{-- FIX-06: in the console, the keyboard and screen-reader help past each control's own markup (resources/js/a11y-core.cjs
     decides, tested under Node): a grouped list's day headings are its items, a table box that scrolls sideways takes
     the focus when nothing in it can, and the rail is one Tab stop that the arrows move through. --}}
<script>
    {!! file_get_contents(resource_path('js/a11y-core.cjs')) !!}
    window.ElA11yCore.install(document, window);
</script>
