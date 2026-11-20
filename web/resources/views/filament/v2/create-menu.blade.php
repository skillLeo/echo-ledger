{{--
    "+" in the new top bar (classic's Create menu): Upload recording, New project and Request link, from any page, in the
    console's language. On the Library the file picker opens right there.
--}}
@php
    $items = array_values(array_filter([
        \App\Filament\Pages\Upload::canAccess() ? [__('Upload recording'), request()->routeIs('filament.app.resources.sessions.index') ? null : \App\Filament\Pages\Upload::getUrl(), 'upload', 'upload'] : null,
        \App\Filament\Resources\Clients\ClientResource::canAccess() && \App\Filament\Resources\Clients\ClientResource::canCreate() ? [__('New project'), \App\Filament\Resources\Clients\ClientResource::getUrl('create'), 'folder', 'project'] : null,
        \App\Filament\Pages\RequestLinksPage::canAccess() ? [__('Request link'), \App\Filament\Pages\RequestLinksPage::getUrl(['action' => 'create']), 'link', 'request'] : null,
    ]));
@endphp
@if ($items !== [] && ! \App\Services\Workspaces\ConsoleAccess::readOnly())
    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <button type="button" class="v2-icon-btn v2-tb-create" aria-label="{{ __('Create') }}" title="{{ __('Create (upload, a project, a request link)') }}">
                <x-el.v2.icon name="plus" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($items as [$label, $url, $icon, $id])
                @if ($url === null)
                    <x-filament::dropdown.list.item tag="button" x-on:click="close(); $dispatch('el-upload-pick')" data-create="{{ $id }}">
                        <span class="v2-menu-item"><x-el.v2.icon :name="$icon" />{{ $label }}</span>
                    </x-filament::dropdown.list.item>
                @else
                    <x-filament::dropdown.list.item tag="a" :href="$url" data-create="{{ $id }}">
                        <span class="v2-menu-item"><x-el.v2.icon :name="$icon" />{{ $label }}</span>
                    </x-filament::dropdown.list.item>
                @endif
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
