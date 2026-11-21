{{--
    The v2.0 Library's saved views: "Views ▾" first with every view of the classic Library and its count (All
    recordings, Today, This week, Waiting for decision, Bookmarked, Failed uploads, Pinned, Untranscribed), then the
    most used as chips; the one you are on is marked. While calls wait for a decision one quiet line says so, with
    Review (parity plan 2: "Needs action" as one quiet banner in the Library), and × to close it until more calls wait
    (App\Filament\Support\V2DismissedBanners).
--}}
@php
    $menu = $this->v2ViewMenu();
    $place = $this->activePlace();
    $waiting = $this->v2Waiting();
@endphp
<div class="v2-chips" role="toolbar" aria-label="{{ __('Saved views') }}">
    <x-filament::dropdown placement="bottom-start" max-height="24rem" wire:key="v2-library-views">
        <x-slot name="trigger">
            <button type="button" @class(['v2-chip', 'is-on' => ! in_array($place, array_column($this->v2Views(), 'key'), true)]) aria-haspopup="menu" data-menu="views">
                <x-el.v2.icon name="tune" />{{ __('Views') }}
                <x-el.v2.icon name="chevron-down" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            @foreach ($menu as $view)
                <x-filament::dropdown.list.item wire:click="openPlace('{{ $view['key'] }}')" x-on:click="close()" :icon="$view['on'] ? 'heroicon-m-check' : null" :badge="$view['count']" badge-color="gray"
                    role="menuitemradio" aria-checked="{{ $view['on'] ? 'true' : 'false' }}" data-option="view-{{ $view['key'] }}">
                    {{ $view['label'] }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
    @foreach ($this->v2Views() as $view)
        <button type="button" @class(['v2-chip', 'is-on' => $view['on']]) aria-pressed="{{ $view['on'] ? 'true' : 'false' }}"
            wire:click="openPlace('{{ $view['key'] }}')" data-view="{{ $view['key'] }}">
            {{ $view['label'] }}
            @if ($view['count'] !== null)
                <span class="v2-chip-count">{{ \Illuminate\Support\Number::format($view['count']) }}</span>
            @endif
        </button>
    @endforeach
</div>
@if ($waiting > 0 && $place !== 'waiting' && ! $this->v2WaitingDismissed())
    <p class="v2-lib-waiting" role="status" data-waiting="{{ $waiting }}">
        <x-el.v2.icon name="clock" />
        <span>{{ trans_choice(':count call waiting for a decision|:count calls waiting for a decision', $waiting, ['count' => \Illuminate\Support\Number::format($waiting)]) }}</span>
        <button type="button" class="v2-link" wire:click="openPlace('waiting')">{{ __('Review') }}</button>
        <span class="v2-tb-spacer" aria-hidden="true"></span>
        <button type="button" class="v2-icon-btn" wire:click="dismissV2Waiting({{ $waiting }})" aria-label="{{ __('Dismiss') }}" title="{{ __('Dismiss') }}" data-dismiss="library"><x-el.v2.icon name="close" /></button>
    </p>
@endif
