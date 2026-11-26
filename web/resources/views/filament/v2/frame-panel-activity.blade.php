{{-- Activity's panel (docs/PLAN_NAV_3_LEVELS.md section 3): All, Needs you (its count strong) and Done. --}}
<ul class="v2-sb-sub" aria-label="{{ __('Activity') }}">
    @foreach (\App\Filament\Support\NavFrame::activityPanel() as $item)
        <li>
            <a href="{{ $item['url'] }}" @class(['v2-sb-subitem', 'is-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif data-activity-view="{{ $item['key'] }}">
                <x-el.v2.icon :name="$item['icon']" />
                <span class="v2-sb-label">{{ $item['label'] }}</span>
                @if ($item['count'] > 0)
                    <span @class(['v2-sb-subcount', 'is-strong' => $item['strong']])>{{ \Illuminate\Support\Number::format($item['count']) }}</span>
                @endif
            </a>
        </li>
    @endforeach
</ul>
