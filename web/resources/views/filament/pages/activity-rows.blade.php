{{-- Activity rows: tile, title (a dot while unread), time, and the line under it. --}}
<ul class="el-rows" role="list">
    @foreach ($items as $item)
        <li class="el-row-item">
            <button type="button" class="el-row el-row-btn" wire:click="open('{{ $item['id'] }}')" wire:key="activity-{{ $item['id'] }}">
                <span class="el-tile el-tile-{{ $item['tone'] }}">@svg($item['icon'], ['aria-hidden' => 'true'])</span>
                <span class="el-row-main">
                    <span class="el-row-top">
                        <span @class(['el-row-title', 'el-unread' => ! $item['read']])>
                            @if (! $item['read'])<span class="el-sr">Unread: </span>@endif{{ $item['title'] }}
                        </span>
                        <time class="el-row-time" datetime="{{ $item['at'] }}" title="{{ $item['full_time'] }}">{{ $item['time'] }}</time>
                    </span>
                    <span class="el-row-sub"><span class="el-row-meta">{{ $item['body'] }}</span></span>
                </span>
            </button>
        </li>
    @endforeach
</ul>
