{{--
    The Library's one toolbar row (docs/PLAN_LIBRARY_FILTERS.md section 1), after the search (titles and transcripts,
    ⌘F / Ctrl+F): [Filters n] opens the filter panel (F), and Sort & display ▾ holds List / Table, the order, Group by
    and Density. The applied filters show as pills on a second row only while some are on (library-pills).
--}}
@php
    $sorts = \App\Filament\Support\LibraryView::SORTS;
    $groups = \App\Filament\Support\LibraryView::v2Groups();
    $count = $this->activeFilterCount();
    $density = \App\Filament\Support\Shell::preferences()['density'];
@endphp
<div class="v2-lib-tools" role="toolbar" aria-label="{{ __('Library tools') }}">
    <button type="button" @class(['v2-chip', 'is-on' => $count > 0]) x-on:click="toggleFilters()" x-bind:aria-expanded="filtersOpen ? 'true' : 'false'"
        aria-controls="v2-lib-filters" data-filters-button title="{{ __('Filters') }} (F)">
        <x-el.v2.icon name="tune" />
        <span>{{ __('Filters') }}</span>
        @if ($count > 0)
            <span class="v2-chip-count" data-filters-count>{{ \Illuminate\Support\Number::format($count) }}</span>
        @endif
        <span class="v2-sr">{{ trans_choice(':count filter on|:count filters on', $count, ['count' => $count]) }}</span>
    </button>

    <x-filament::dropdown placement="bottom-end" max-height="32rem" wire:key="v2-library-display">
        <x-slot name="trigger">
            <button type="button" class="v2-chip" aria-haspopup="menu" data-menu="display">
                <x-el.v2.icon name="list" />{{ __('Sort & display') }}
                <x-el.v2.icon name="chevron-down" />
            </button>
        </x-slot>
        <x-filament::dropdown.header role="presentation" data-menu-heading>{{ __('View') }}</x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @foreach (\App\Filament\Support\Shell::LAYOUTS as $key => $label)
                <x-filament::dropdown.list.item wire:click="setLibraryLayout('{{ $key }}')" x-on:click="close()" :icon="$this->libraryLayout === $key ? 'heroicon-m-check' : null"
                    role="menuitemradio" aria-checked="{{ $this->libraryLayout === $key ? 'true' : 'false' }}" data-layout="{{ $key }}">
                    {{ __($label) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
        <x-filament::dropdown.header role="presentation" data-menu-heading>{{ __('Sort') }}</x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @foreach ($sorts as $key => $label)
                <x-filament::dropdown.list.item wire:click="setLibrarySort('{{ $key }}')" x-on:click="close()" :icon="$this->librarySort === $key ? 'heroicon-m-check' : null"
                    role="menuitemradio" data-option="sort-{{ $key }}" aria-checked="{{ $this->librarySort === $key ? 'true' : 'false' }}">
                    {{ __($label) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
        <x-filament::dropdown.header role="presentation" data-menu-heading>{{ __('Group by') }}</x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @foreach ($groups as $key => $label)
                <x-filament::dropdown.list.item wire:click="setLibraryGroup('{{ $key }}')" x-on:click="close()" :icon="$this->libraryGroup === $key ? 'heroicon-m-check' : null"
                    role="menuitemradio" data-option="group-{{ $key }}" aria-checked="{{ $this->libraryGroup === $key ? 'true' : 'false' }}">
                    {{ $label }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
        <x-filament::dropdown.header role="presentation" data-menu-heading>{{ __('Density') }}</x-filament::dropdown.header>
        <x-filament::dropdown.list>
            @foreach (\App\Filament\Support\Shell::DENSITIES as $key => $label)
                <x-filament::dropdown.list.item wire:click="setLibraryDensity('{{ $key }}')" x-on:click="close()" :icon="$density === $key ? 'heroicon-m-check' : null"
                    role="menuitemradio" data-option="density-{{ $key }}" aria-checked="{{ $density === $key ? 'true' : 'false' }}">
                    {{ __($label) }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
