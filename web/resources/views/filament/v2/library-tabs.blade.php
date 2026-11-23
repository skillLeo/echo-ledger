{{--
    The Library's top tabs (docs/PLAN_NAV_3_LEVELS.md section 4; docs/API.md "The Library's tabs"): All · Calls ·
    Meetings · Voice notes, each with how many the place, the filters and the search keep in it. A tab is in the address
    (kind), so a link and Back keep it; the filters' pills come under them while some are on.
--}}
@php
    // How many the list holds, said once in the header too: under the wide width (or while the filters push the list)
    // the table's own "Showing 1 to 7 of 7" folds away with its narrower box, and the count must never depend on it.
    $total = (int) (collect($tabs)->firstWhere('active', true)['count'] ?? 0);
@endphp
<p class="v2-lib-total" data-library-total>{{ $total === 1 ? __(':n recording', ['n' => 1]) : __(':n recordings', ['n' => \Illuminate\Support\Number::format($total)]) }}</p>
<nav class="v2-tabs v2-lib-tabs" aria-label="{{ __('Kinds of recording') }}" data-library-tabs>
    @foreach ($tabs as $tab)
        <button type="button" @class(['v2-tab', 'is-on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif
            wire:click="setLibraryKind({{ $tab['key'] === 'all' ? 'null' : \Illuminate\Support\Js::from($tab['key']) }})" data-library-tab="{{ $tab['key'] }}">
            <span>{{ $tab['label'] }}</span>
            <span class="v2-tab-count">{{ \Illuminate\Support\Number::format($tab['count']) }}</span>
        </button>
    @endforeach
</nav>
