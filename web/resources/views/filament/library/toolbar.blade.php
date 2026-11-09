{{--
    The Library's one compact toolbar (since v1.6, P5.2), under the search pill: what to show (the smart views, with
    their counts), which folder (Inbox, the apps, Phone, Voice notes, Imported, Shared with me), then Group by and Sort
    by with the phone's and the desktop's names. It replaces the three rows of chips. A group header's select box only
    selects; its name and arrow fold it (the capture listener below stops the box's click from reaching the header).
--}}
@php
    $tabs = $this->getCachedTabs();
    $activeTab = (string) ($this->activeTab ?: 'all');
    $shown = $tabs[$activeTab] ?? null;
    $folders = $this->folders();
    $folder = $this->folder !== null ? ($folders[$this->folder] ?? null) : null;
    $menus = [
        ['group', 'Group by', \App\Filament\Support\LibraryView::GROUPS, $this->libraryGroup, 'setLibraryGroup', 'heroicon-o-rectangle-group'],
        ['sort', 'Sort by', \App\Filament\Support\LibraryView::SORTS, $this->librarySort, 'setLibrarySort', 'heroicon-o-arrows-up-down'],
    ];
@endphp
<div class="el-library-toolbar" role="toolbar" aria-label="Library view"
    x-init="window.elGroupBoxOnlySelects || (window.elGroupBoxOnlySelects = true, document.addEventListener('click', (e) => { const box = e.target instanceof Element ? e.target.closest('.el-library .fi-ta-group-checkbox') : null; if (box && ! box.dataset.elOnlySelects) { box.dataset.elOnlySelects = '1'; box.addEventListener('click', (ev) => ev.stopPropagation()) } }, true))">
    <x-filament::dropdown placement="bottom-start" max-height="24rem" wire:key="library-menu-view">
        <x-slot name="trigger">
            <button type="button" @class(['el-tool', 'el-on' => $activeTab !== 'all']) data-menu="view" aria-haspopup="menu">
                @svg('heroicon-o-funnel', ['aria-hidden' => 'true'])
                <span class="el-tool-label">{{ $activeTab === 'all' || $shown === null ? 'All recordings' : $shown->getLabel() }}</span>
                @if ($activeTab !== 'all' && filled($shown?->getBadge()))
                    <span class="el-chip-count">{{ $shown->getBadge() }}</span>
                @endif
                @svg('heroicon-m-chevron-down', ['aria-hidden' => 'true'])
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($tabs as $key => $tab)
                <x-filament::dropdown.list.item wire:click="$set('activeTab', '{{ $key }}')" x-on:click="close()"
                    :icon="(string) $key === $activeTab ? 'heroicon-m-check' : null" :badge="$tab->getBadge()" badge-color="gray"
                    data-option="view-{{ $key }}" role="menuitemradio" aria-checked="{{ (string) $key === $activeTab ? 'true' : 'false' }}">
                    {{ (string) $key === 'all' ? 'All recordings' : $tab->getLabel() }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    <x-filament::dropdown placement="bottom-start" max-height="24rem" wire:key="library-menu-folder">
        <x-slot name="trigger">
            <button type="button" @class(['el-tool', 'el-on' => $folder !== null]) data-menu="folder" aria-haspopup="menu">
                @svg($folder !== null ? 'heroicon-s-'.$folder['icon'] : 'heroicon-o-folder', ['aria-hidden' => 'true'])
                <span class="el-tool-label">{{ $folder['label'] ?? 'All folders' }}</span>
                @if ($folder !== null)
                    <span class="el-chip-count">{{ $folder['count'] }}</span>
                @endif
                @svg('heroicon-m-chevron-down', ['aria-hidden' => 'true'])
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item wire:click="chooseFolder(null)" x-on:click="close()" :icon="$folder === null ? 'heroicon-m-check' : 'heroicon-o-squares-2x2'"
                data-option="folder-all" role="menuitemradio" aria-checked="{{ $folder === null ? 'true' : 'false' }}">
                All folders
            </x-filament::dropdown.list.item>
            @foreach ($folders as $key => $item)
                @continue($item['count'] === 0 && $this->folder !== $key && $key !== 'inbox')
                @if ($item['url'] !== null)
                    <x-filament::dropdown.list.item tag="a" :href="$item['url']" wire:navigate :icon="'heroicon-o-'.$item['icon']" :badge="$item['count']" badge-color="gray"
                        data-option="folder-{{ $key }}">
                        {{ $item['label'] }}
                    </x-filament::dropdown.list.item>
                @else
                    <x-filament::dropdown.list.item wire:click="chooseFolder('{{ $key }}')" x-on:click="close()" :icon="$this->folder === $key ? 'heroicon-m-check' : 'heroicon-o-'.$item['icon']"
                        :badge="$item['count']" badge-color="gray" data-option="folder-{{ $key }}" role="menuitemradio" aria-checked="{{ $this->folder === $key ? 'true' : 'false' }}">
                        {{ $item['label'] }}
                    </x-filament::dropdown.list.item>
                @endif
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    <span class="el-tool-spacer" aria-hidden="true"></span>

    {{-- Since v1.6 (P4.4): the phone's list or a table (Columns chooses what it shows). --}}
    <div class="el-seg el-layout-switch" role="group" aria-label="Show as">
        @foreach (\App\Filament\Support\Shell::LAYOUTS as $key => $label)
            <button type="button" @class(['el-segment', 'el-on' => $this->libraryLayout === $key]) aria-pressed="{{ $this->libraryLayout === $key ? 'true' : 'false' }}"
                wire:click="setLibraryLayout('{{ $key }}')" data-layout="{{ $key }}" title="{{ $label }}">
                @svg($key === 'list' ? 'heroicon-o-queue-list' : 'heroicon-o-table-cells', ['aria-hidden' => 'true'])
                <span class="el-tool-label">{{ $label }}</span>
            </button>
        @endforeach
    </div>

    @foreach ($menus as [$menu, $label, $options, $current, $method, $icon])
        <x-filament::dropdown placement="bottom-end" wire:key="library-menu-{{ $menu }}">
            <x-slot name="trigger">
                <button type="button" class="el-tool el-menu-trigger" data-menu="{{ $menu }}" aria-haspopup="menu">
                    @svg($icon, ['aria-hidden' => 'true'])
                    <span class="el-tool-label">{{ $label }}: <strong>{{ $options[$current] }}</strong></span>
                    @svg('heroicon-m-chevron-down', ['aria-hidden' => 'true'])
                </button>
            </x-slot>
            <x-filament::dropdown.list>
                @foreach ($options as $key => $option)
                    <x-filament::dropdown.list.item wire:click="{{ $method }}('{{ $key }}')" x-on:click="close()"
                        :icon="$key === $current ? 'heroicon-m-check' : null" data-option="{{ $menu }}-{{ $key }}"
                        role="menuitemradio" aria-checked="{{ $key === $current ? 'true' : 'false' }}">
                        {{ $option }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    @endforeach
</div>
