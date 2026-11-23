{{--
    Settings (since v1.7): the search and the sections on the left, the open page on the right (the Overview when none
    is open). Below 760 px of its own width: the list, then a page with "Settings" to go back.
--}}
@php
    use App\Filament\Auth\EditProfile;
    use Illuminate\Support\Js;

    $context = $this->context();
    $searching = $this->isSearching();
    $mode = $searching ? 'search' : ($this->subpage === '' ? 'overview' : 'page');
    $sections = $this->settingsSections();
    // With the three levels (docs/PLAN_NAV_3_LEVELS.md) Settings' groups and its search are the panel's: the page shows
    // the open group's pages as its inner list when it has several, and its own list stays hidden (nothing twice).
    $framed = \App\Filament\Support\ConsoleV2::frame();
    $inner = $framed && $mode === 'page' ? \App\Filament\Support\NavFrame::settingsInnerList() : null;
    $innerList = $inner !== null ? view('filament.settings.inner-list', ['inner' => $inner])->render() : '';
    $frameClass = $framed ? ' is-framed'.($inner !== null ? ' has-inner' : '') : '';
@endphp
<x-filament-panels::page class="el-settings-page">
    <div class="el-settings-wrap">
        <div class="el-settings{{ $frameClass }}" data-mode="{{ $mode }}" data-page="{{ $this->subpage ?: 'overview' }}">
            {!! $innerList !!}<nav class="el-settings-nav" aria-label="{{ __('Settings') }}"{!! $framed ? ' hidden' : '' !!}>
                <div class="el-set-search" role="search" x-data
                    x-on:keydown.escape.prevent="$wire.clearSettingsSearch(); $refs.q.blur()"
                    x-on:keydown.down.prevent="window.elSettings?.move(1)" x-on:keydown.up.prevent="window.elSettings?.move(-1)"
                    x-on:keydown.enter.prevent="window.elSettings?.open()">
                    @svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true'])
                    <input type="search" x-ref="q" wire:model.live.debounce.120ms="settingsQuery" placeholder="{{ __('Search settings') }}" aria-label="{{ __('Search settings') }}"
                        autocomplete="off" spellcheck="false" aria-controls="el-set-results" data-settings-search>
                    @if ($this->settingsQuery !== '')
                        <button type="button" class="el-set-clear" wire:click="clearSettingsSearch" aria-label="{{ __('Clear') }}">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
                    @endif
                </div>

                @foreach ($sections as $section)
                    <x-el.section :title="__($section['title'])">
                        @foreach ($section['rows'] as $row)
                            <a href="{{ $row['url'] }}" @class(['el-set-row', 'has-icon', 'el-set-nav-row']) @if ($row['current']) aria-current="page" @endif data-nav="{{ $row['page'] }}">
                                @svg($row['icon'], ['class' => 'el-set-icon', 'aria-hidden' => 'true'])
                                <span class="el-set-text"><span class="el-set-title">{{ __($row['title']) }}</span></span>
                                @if ($searching && $row['count'] > 0)
                                    <span class="el-set-count" aria-label="{{ $row['count'] === 1 ? '1 result' : $row['count'].' results' }}">{{ $row['count'] }}</span>
                                @elseif ($row['page'] === 'appearance')
                                    <span class="el-set-value" x-data x-text="({ light: {{ Js::from(__('Light')) }}, dark: {{ Js::from(__('Dark')) }} })[localStorage.getItem('filament:theme:mode')] ?? {{ Js::from(__('System')) }}">{{ __('System') }}</span>
                                @elseif (filled($row['value']))
                                    <span class="el-set-value">{{ $row['value'] }}</span>
                                @endif
                                @svg('heroicon-o-chevron-right', ['class' => 'el-set-chevron', 'aria-hidden' => 'true'])
                            </a>
                        @endforeach
                    </x-el.section>
                @endforeach

                @unless ($searching)
                    <section class="el-set-section">
                        <div class="el-set-box">
                            <button type="button" id="setting-signout" tabindex="-1" class="el-set-row" wire:click="mountAction('signOut')">
                                <span class="el-set-text"><span class="el-set-title">{{ __('Sign out') }}</span></span>
                            </button>
                        </div>
                    </section>
                @endunless
            </nav>

            <main class="el-settings-main" id="el-set-content">
                @if ($searching)
                    @include('filament.settings.search-results', ['results' => $this->searchResults(), 'query' => $this->settingsQuery])
                @elseif ($this->subpage === '')
                    <h1 class="el-set-h1">Settings</h1>
                    @include('filament.settings.profile-card')
                    @include('filament.settings.state-row')
                    @include('filament.settings.this-month')
                @else
                    <a href="{{ EditProfile::settingsUrl() }}" class="el-set-back el-set-only-narrow">@svg('heroicon-o-chevron-left', ['aria-hidden' => 'true']) {{ __('Settings') }}</a>
                    <h1 class="el-set-h1">{{ $this->getTitle() }}</h1>
                    {{ $this->content }}
                @endif
            </main>
        </div>
    </div>
    @include('filament.settings.script')
</x-filament-panels::page>
