{{--
    "+" in the top bar (since v1.6, P4.3): Upload, New project and Request link, from any page. The console does not
    record (the phone and the computer do): "Record now" in Ctrl/⌘+K opens the apps' page.
--}}
@php
    $items = array_values(array_filter([
        \App\Filament\Pages\Upload::canAccess() ? ['Upload files', request()->routeIs('filament.app.resources.sessions.index') ? null : \App\Filament\Pages\Upload::getUrl(), 'heroicon-o-arrow-up-tray', 'upload'] : null,
        \App\Filament\Resources\Clients\ClientResource::canAccess() && \App\Filament\Resources\Clients\ClientResource::canCreate() ? ['New project', \App\Filament\Resources\Clients\ClientResource::getUrl('create'), 'heroicon-o-folder-plus', 'project'] : null,
        \App\Filament\Pages\RequestLinksPage::canAccess() ? ['Request link', \App\Filament\Pages\RequestLinksPage::getUrl(['action' => 'create']), 'heroicon-o-inbox-arrow-down', 'request'] : null,
    ]));
@endphp
@if ($items !== [] && ! \App\Services\Workspaces\ConsoleAccess::readOnly())
    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <button type="button" class="el-tb-icon el-tb-create" aria-label="Create" title="Create (upload, a project, a request link)">
                @svg('heroicon-o-plus', ['aria-hidden' => 'true'])
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($items as [$label, $url, $icon, $id])
                @if ($url === null)
                    {{-- On the Library the file picker opens right here. --}}
                    <x-filament::dropdown.list.item tag="button" :icon="$icon" x-on:click="close(); $dispatch('el-upload-pick')" data-create="{{ $id }}">{{ $label }}</x-filament::dropdown.list.item>
                @else
                    <x-filament::dropdown.list.item tag="a" :href="$url" :icon="$icon" data-create="{{ $id }}">{{ $label }}</x-filament::dropdown.list.item>
                @endif
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
