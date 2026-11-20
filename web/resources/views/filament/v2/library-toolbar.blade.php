{{--
    The v2.0 Library's quick filters beside the search: Source (the apps, Phone, Voice notes, Imported, the Inbox), the
    order, Group by (date, source, project, language, status, device, none) and List / Table. The Filters panel
    (project, label, dates…) is the button in the search field; what it applies shows as chips under it. In the table
    Columns (the table's own button) chooses what it shows.
--}}
@php
    $folders = $this->folders();
    unset($folders['shared']);
    $folder = $this->folder !== null ? ($folders[$this->folder] ?? null) : null;
    $sorts = \App\Filament\Support\LibraryView::SORTS;
    $groups = \App\Filament\Support\LibraryView::v2Groups();
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

    <x-filament::dropdown placement="bottom-start" wire:key="v2-library-group">
        <x-slot name="trigger">
            <button type="button" @class(['v2-chip', 'is-on' => $this->libraryGroup !== \App\Filament\Support\LibraryView::DEFAULT_GROUP]) aria-haspopup="menu" data-menu="group"
                title="{{ __('Group by') }}">
                <x-el.v2.icon name="group" />{{ __('Group by') }}: {{ $groups[$this->libraryGroup] ?? $groups['date'] }}
                <x-el.v2.icon name="chevron-down" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($groups as $key => $label)
                <x-filament::dropdown.list.item wire:click="setLibraryGroup('{{ $key }}')" x-on:click="close()" role="menuitemradio" data-option="group-{{ $key }}"
                    aria-checked="{{ $this->libraryGroup === $key ? 'true' : 'false' }}">
                    {{ $label }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    <div class="v2-seg" role="group" aria-label="{{ __('Show as') }}">
        @foreach (\App\Filament\Support\Shell::LAYOUTS as $key => $label)
            <button type="button" @class(['v2-seg-item', 'is-on' => $this->libraryLayout === $key]) aria-pressed="{{ $this->libraryLayout === $key ? 'true' : 'false' }}"
                wire:click="setLibraryLayout('{{ $key }}')" data-layout="{{ $key }}" title="{{ __($label) }}">
                <x-el.v2.icon :name="$key" /><span class="v2-seg-label">{{ __($label) }}</span>
            </button>
        @endforeach
    </div>
</div>
