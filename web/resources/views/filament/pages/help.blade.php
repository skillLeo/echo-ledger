{{-- Help (v1.8 P6.4, App\Filament\Pages\Help): the guide searched, one section open; What's new; Contact support above. --}}
<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:minmax(0,1fr);gap:1.5rem" class="el-help-page">
        <x-filament::section :heading="__('The guide')">
            <label for="el-help-q" class="el-sr">{{ __('Search the guide') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input id="el-help-q" type="search" wire:model.live.debounce.300ms="q" :placeholder="__('Search the guide: captions, voice notes, sharing…')" />
            </x-filament::input.wrapper>
            @if ($results === [])
                <p style="margin:.75rem 0 0;opacity:.7">{{ __('Nothing in the guide matches “:query”. Try other words, or contact support.', ['query' => $q]) }}</p>
            @else
                <ul style="margin:.75rem 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:.5rem" aria-label="{{ __('Guide sections') }}">
                    @foreach ($results as $r)
                        <li>
                            <button type="button" wire:click="open(@js($r['id']))" style="text-align:left;width:100%" @if (($open['id'] ?? null) === $r['id']) aria-current="true" @endif>
                                <strong>{{ $r['title'] }}</strong>
                                @if ($q !== '')<span style="display:block;font-size:.875rem;opacity:.75">{{ $r['excerpt'] }}</span>@endif
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        @if ($open)
            <x-filament::section :heading="$open['title']" id="guide-{{ $open['id'] }}">
                <div class="fi-prose" style="max-width:72ch">{!! $html !!}</div>
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('What\'s new')" :description="__('The latest versions of the apps and what changed.')" id="whats-new">
            @if ($news === [])
                <p style="margin:0;opacity:.7">{{ __('No release notes yet.') }}</p>
            @else
                <ul style="margin:0;padding:0;list-style:none;display:flex;flex-direction:column;gap:1rem">
                    @foreach ($news as $n)
                        <li>
                            <strong>{{ ['android' => __('Phone'), 'windows' => 'Windows', 'mac' => 'Mac'][$n['platform']] ?? $n['platform'] }} {{ $n['version'] }}</strong>
                            @if ($n['released_at'])<span style="opacity:.7"> · {{ \App\Filament\Support\ConsoleDates::date(\Illuminate\Support\Carbon::parse($n['released_at'])) }}</span>@endif
                            <p style="margin:.25rem 0 0;white-space:pre-line">{{ $n['notes'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
