{{--
    Settings' panel (docs/PLAN_NAV_3_LEVELS.md section 3): the search, then Transcription & language, Live & overlays,
    Privacy & data, Notifications, Appearance, Account & plan, Setup & health, Shortcuts, About & help (those this
    console has; Recording is the apps'). A group of several pages shows them as the page's inner list.
--}}
<form method="get" action="{{ \App\Filament\Support\NavFrame::settingsUrl() }}" class="v2-panel-search" role="search">
    <x-el.v2.icon name="search" />
    <input type="search" name="q" value="{{ request()->routeIs('filament.app.auth.profile') ? (string) request()->query('q', '') : '' }}"
        placeholder="{{ __('Search settings') }}" aria-label="{{ __('Search settings') }}" autocomplete="off" spellcheck="false" data-panel-settings-search>
</form>
<ul class="v2-sb-sub" aria-label="{{ __('Settings') }}">
    @foreach (\App\Filament\Support\NavFrame::settingsGroups() as $group)
        <li>
            <a href="{{ $group['url'] }}" @class(['v2-sb-subitem', 'is-on' => $group['active']]) @if ($group['active']) aria-current="page" @endif data-settings-group="{{ $group['key'] }}">
                <span class="v2-sb-label">{{ $group['label'] }}</span>
                @if (count($group['pages']) > 1)
                    <span class="v2-sb-subcount">{{ count($group['pages']) }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
