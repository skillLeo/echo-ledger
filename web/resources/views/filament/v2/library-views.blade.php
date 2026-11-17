{{-- The v2.0 Library's saved views, as chips with their counts; the one you are on is marked. --}}
<div class="v2-chips" role="toolbar" aria-label="{{ __('Saved views') }}">
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
