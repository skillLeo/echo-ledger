{{-- A page's top tabs (docs/PLAN_NAV_3_LEVELS.md section 4), links to its everyday splits; the open one underlined. --}}
<nav class="v2-tabs v2-page-tabs" aria-label="{{ __('Sections') }}" data-page-tabs>
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}" @class(['v2-tab', 'is-on' => $tab['active']]) @if ($tab['active']) aria-current="page" @endif data-page-tab="{{ $tab['key'] }}">{{ $tab['label'] }}</a>
    @endforeach
</nav>
