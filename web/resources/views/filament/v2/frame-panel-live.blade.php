{{--
    Live's panel (docs/PLAN_NAV_3_LEVELS.md section 3): Call recording (in the phone and computer apps), Live Assist
    (Upcoming, Past reports, Practice) where the person has it, and the apps' live tools, each on the Live page.
--}}
@foreach (\App\Filament\Support\NavFrame::livePanel() as $group)
    <div class="v2-sb-group">
        <p class="v2-panel-group-title">{{ $group['title'] }}</p>
        <ul class="v2-sb-sub" aria-label="{{ $group['title'] }}">
            @foreach ($group['items'] as $item)
                <li>
                    <a href="{{ $item['url'] }}" @class(['v2-sb-subitem', 'is-on' => $item['active']]) @if ($item['active']) aria-current="page" @endif data-live="{{ $item['key'] }}">
                        <x-el.v2.icon :name="$item['icon']" />
                        <span class="v2-sb-label">{{ $item['label'] }}</span>
                        @if (($item['note'] ?? null) !== null)
                            <span class="v2-sb-subcount">{{ $item['note'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endforeach
