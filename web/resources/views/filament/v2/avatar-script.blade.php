{{-- DES-08: in the console, a person's photo that does not load becomes their initials on their colour (x-el.avatar;
     resources/js/avatar-core.cjs decides, tested under Node). --}}
<script>
    {!! file_get_contents(resource_path('js/avatar-core.cjs')) !!}
    window.ElAvatarCore.install(document);
</script>
