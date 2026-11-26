{{--
    The applied filters as pills, a second row only while some are on (docs/PLAN_LIBRARY_FILTERS.md section 1):
    "Source: WhatsApp, Zoom ×", "Date is not: Today ×". A pill opens its menu: is / is not, each value (untick to take it
    away), Edit in filters, Remove. Then Clear all, Save as view, and Save changes while a saved view is open and changed.
--}}
@php
    $pills = $this->filterPills();
    $view = $this->currentSavedView();
@endphp
@if ($pills !== [])
    <div class="v2-lib-pills" role="group" aria-label="{{ __('Filters on') }}" data-pills>
        @foreach ($pills as $pill)
            <span class="v2-pill" data-pill-wrap="{{ $pill['section'] }}">
                <x-filament::dropdown placement="bottom-start" max-height="24rem" wire:key="v2-pill-{{ $pill['section'] }}">
                    <x-slot name="trigger">
                        <button type="button" class="v2-pill-main" aria-haspopup="menu" data-pill="{{ $pill['section'] }}"
                            aria-label="{{ __('Edit the :section filter', ['section' => $pill['label']]) }}">
                            <span class="v2-pill-key">{{ $pill['op'] === 'is_not' ? __(':section is not', ['section' => $pill['label']]) : $pill['label'] }}:</span>
                            <bdi class="v2-pill-values">{{ $pill['text'] }}</bdi>
                        </button>
                    </x-slot>
                    @if ($pill['section'] !== 'date')
                        <x-filament::dropdown.list>
                            @foreach (['is' => __('Is'), 'is_not' => __('Is not')] as $op => $words)
                                <x-filament::dropdown.list.item wire:click="setFilterOp('{{ $pill['section'] }}', '{{ $op }}')" :icon="$pill['op'] === $op ? 'heroicon-m-check' : null"
                                    role="menuitemradio" aria-checked="{{ $pill['op'] === $op ? 'true' : 'false' }}" data-pill-op="{{ $op }}">
                                    {{ $words }}
                                </x-filament::dropdown.list.item>
                            @endforeach
                        </x-filament::dropdown.list>
                    @endif
                    <x-filament::dropdown.list>
                        @foreach ($pill['values'] as $value)
                            <x-filament::dropdown.list.item wire:click="toggleFilterValue('{{ $pill['section'] }}', {{ \Illuminate\Support\Js::from($value['value']) }})" icon="heroicon-m-check"
                                role="menuitemcheckbox" aria-checked="true" data-pill-value="{{ $value['value'] }}">
                                {{ $value['label'] }}
                            </x-filament::dropdown.list.item>
                        @endforeach
                    </x-filament::dropdown.list>
                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item x-on:click="close(); openFilters('{{ $pill['section'] }}')" data-pill-edit>
                            {{ __('Edit in filters') }}
                        </x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item wire:click="removeFilter('{{ $pill['section'] }}')" color="danger" data-pill-remove>
                            {{ __('Remove filter') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
                <button type="button" class="v2-pill-x" wire:click="removeFilter('{{ $pill['section'] }}')" data-pill-x="{{ $pill['section'] }}"
                    aria-label="{{ __('Remove the :section filter', ['section' => $pill['label']]) }}" title="{{ __('Remove filter') }}">
                    <x-el.v2.icon name="close" />
                </button>
            </span>
        @endforeach
        <button type="button" class="v2-link" wire:click="clearFilters" data-clear-filters>{{ __('Clear all') }}</button>
        <button type="button" class="v2-link" wire:click="mountAction('saveView')" data-save-view>{{ __('Save as view') }}</button>
        @if ($view !== null && $this->savedViewChanged())
            <button type="button" class="v2-link" wire:click="mountAction('updateView')" data-update-view>{{ __('Save changes to :name', ['name' => $view['name']]) }}</button>
        @endif
    </div>
@endif
