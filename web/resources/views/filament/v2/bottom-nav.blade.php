{{--
    The v2.0 bottom bar on phone-width screens (up to 720 px, the tokens' compact breakpoint), as the parity plan's
    phone bar: Library · Projects · ● Record · Live · Ask. Record, in the middle in the accent colour, records a voice
    note here (the Upload page); Home and Settings are in the menu and the avatar menu. A module this console can't
    open shows with its reason.
--}}
@auth
    @php
        $modules = collect(\App\Filament\Support\ConsoleV2Nav::modules())->keyBy('key');
        $upload = \App\Filament\Support\ConsoleV2Nav::upload();
        $tabs = array_values(array_filter([$modules->get('library'), $modules->get('projects'), $upload !== null ? 'record' : null, $modules->get('live'), $modules->get('ask')]));
    @endphp
    <nav class="v2-bn" aria-label="{{ __('Main') }}">
        @foreach ($tabs as $tab)
            @if ($tab === 'record')
                <a href="{{ \App\Filament\Pages\Upload::getUrl(['record' => 1]) }}" class="v2-bn-item v2-bn-record" data-bottom="record">
                    <span class="v2-bn-record-dot"><x-el.v2.icon name="mic" /></span>
                    <span>{{ __('Record') }}</span>
                </a>
            @elseif ($tab['url'] !== null)
                <a href="{{ $tab['url'] }}" @class(['v2-bn-item', 'is-on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif data-bottom="{{ $tab['key'] }}">
                    <x-el.v2.icon :name="$tab['icon']" :filled="$tab['active']" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            @else
                <span class="v2-bn-item is-off" aria-disabled="true" title="{{ $tab['reason'] }}" data-bottom="{{ $tab['key'] }}">
                    <x-el.v2.icon :name="$tab['icon']" />
                    <span>{{ $tab['label'] }}</span>
                </span>
            @endif
        @endforeach
    </nav>
@endauth
