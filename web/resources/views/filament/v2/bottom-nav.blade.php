{{--
    The v2.0 bottom bar on phone-width screens (up to 720 px, the tokens' compact breakpoint): Home, Library, Live and
    Ask, as in the phone app; Settings is in the avatar menu. A module this console can't open shows with its reason.
--}}
@auth
    @php($tabs = array_values(array_filter(\App\Filament\Support\ConsoleV2Nav::modules(), fn (array $m): bool => $m['key'] !== 'settings')))
    <nav class="v2-bn" aria-label="{{ __('Main') }}">
        @foreach ($tabs as $tab)
            @if ($tab['url'] !== null)
                <a href="{{ $tab['url'] }}" @class(['v2-bn-item', 'is-on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif>
                    <x-el.v2.icon :name="$tab['icon']" :filled="$tab['active']" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            @else
                <span class="v2-bn-item is-off" aria-disabled="true" title="{{ $tab['reason'] }}">
                    <x-el.v2.icon :name="$tab['icon']" />
                    <span>{{ $tab['label'] }}</span>
                </span>
            @endif
        @endforeach
    </nav>
@endauth
