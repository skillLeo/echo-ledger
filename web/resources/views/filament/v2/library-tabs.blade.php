{{--
    The Library's top tabs (docs/PLAN_NAV_3_LEVELS.md section 4; docs/API.md "The Library's tabs"): All · Calls ·
    Meetings · Voice notes, each with how many the place, the filters and the search keep in it. A tab is in the address
    (kind), so a link and Back keep it; the filters' pills come under them while some are on.
--}}
<nav class="v2-tabs v2-lib-tabs" aria-label="{{ __('Kinds of recording') }}" data-library-tabs>
    @foreach ($this->libraryTabs() as $tab)
        <button type="button" @class(['v2-tab', 'is-on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif
            wire:click="setLibraryKind({{ $tab['key'] === 'all' ? 'null' : \Illuminate\Support\Js::from($tab['key']) }})" data-library-tab="{{ $tab['key'] }}">
            <span>{{ $tab['label'] }}</span>
            <span class="v2-tab-count">{{ \Illuminate\Support\Number::format($tab['count']) }}</span>
        </button>
    @endforeach
</nav>
