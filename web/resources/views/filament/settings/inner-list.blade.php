{{-- With the three levels: the open settings group's pages, the page's inner list (docs/PLAN_NAV_3_LEVELS.md section 4). --}}
<nav class="el-settings-nav v2-inner-list" aria-label="{{ $inner['label'] }}" data-inner-list>
    <p class="v2-inner-list-title">{{ $inner['label'] }}</p>
    @foreach ($inner['pages'] as $page)
        <a href="{{ $page['url'] }}" @class(['v2-inner-item', 'is-on' => $page['active']]) @if ($page['active']) aria-current="page" @endif>{{ $page['label'] }}</a>
    @endforeach
</nav>
