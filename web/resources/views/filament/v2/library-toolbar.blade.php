{{--
    The v2.0 Library's quick filters beside the search: Source (the apps, Phone, Voice notes, Imported, the Inbox) and
    the order. The Filters panel (project, label, dates…) is the button in the search field; what it applies shows as
    chips under it.
--}}
@php
    $folders = $this->folders();
    unset($folders['shared']);
    $folder = $this->folder !== null ? ($folders[$this->folder] ?? null) : null;
    $sorts = \App\Filament\Support\LibraryView::SORTS;
@endphp
<div class="v2-lib-tools" role="toolbar" aria-label="{{ __('Filters') }}">
    <x-filament::dropdown placement="bottom-start" max-height="24rem" wire:key="v2-library-source">
        <x-slot name="trigger">
            <button type="button" @class(['v2-chip', 'is-on' => $folder !== null]) aria-haspopup="menu" data-menu="source">
                {{ $folder !== null ? __($folder['label']) : __('Source') }}
                <x-el.v2.icon name="chevron-down" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item wire:click="chooseFolder(null)" x-on:click="close()" role="menuitemradio" aria-checked="{{ $folder === null ? 'true' : 'false' }}">
                {{ __('Any source') }}
            </x-filament::dropdown.list.item>
            @foreach ($folders as $key => $f)
                <x-filament::dropdown.list.item wire:click="chooseFolder('{{ $key }}')" x-on:click="close()" :badge="$f['count'] > 0 ? $f['count'] : null" badge-color="gray"
                    role="menuitemradio" aria-checked="{{ $this->folder === $key ? 'true' : 'false' }}">
                    {{ __($f['label']) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    <x-filament::dropdown placement="bottom-start" wire:key="v2-library-sort">
        <x-slot name="trigger">
            <button type="button" class="v2-chip" aria-haspopup="menu" data-menu="sort">
                {{ __($sorts[$this->librarySort] ?? 'Newest') }}
                <x-el.v2.icon name="chevron-down" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($sorts as $key => $label)
                <x-filament::dropdown.list.item wire:click="setLibrarySort('{{ $key }}')" x-on:click="close()" role="menuitemradio" aria-checked="{{ $this->librarySort === $key ? 'true' : 'false' }}">
                    {{ __($label) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
