{{--
    UX-22 3b: what the right-click menus of this page's or panel's objects need (App\Filament\Support\ObjectMenus), read by
    filament.v2.context-menu-script; a pick goes back to this component's objectAction. Each action's glyph is the one
    the console draws it with (the Library's own menu has the same templates; the first found is used).
--}}
<script type="application/json" data-v2-object-menus>@json($menus)</script>
<div hidden data-v2-menu-icons>
    @foreach (['TrashIcon' => 'trash', 'RenameIcon' => 'rename', 'CopyIcon' => 'copy', 'FilterIcon' => 'tune', 'CheckIcon' => 'check', 'LabelIcon' => 'label',
        'PinIcon' => 'star', 'UsersIcon' => 'users', 'check' => 'check', 'more' => 'chevron-end'] as $icon => $glyph)
        <template data-icon="{{ $icon }}"><x-el.v2.icon :name="$glyph" /></template>
    @endforeach
</div>
