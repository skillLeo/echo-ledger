{{-- The client's recordings, newest first, as Library rows. --}}
<div style="max-width: 880px">
    @if ($recordings === [])
        <div class="el-empty">
            <span class="el-empty-icon">@svg('heroicon-o-play-circle', ['aria-hidden' => 'true'])</span>
            <p class="el-empty-title">No recordings yet</p>
            <p class="el-empty-text">Recordings filed under {{ $client->name }} show here, from the phone, the computer or Upload.</p>
        </div>
    @else
        <ul class="el-rows" role="list">
            @foreach ($recordings as $row)
                <li class="el-row-item" wire:key="client-recording-{{ $row['id'] }}">
                    <a href="{{ $row['url'] }}" class="el-row el-row-btn" wire:navigate>
                        @include('filament.library.tile', ['row' => $row])
                        <span class="el-row-main">
                            <span class="el-row-top">
                                <span class="el-row-title">{{ $row['title'] }}</span>
                                <time class="el-row-time" datetime="{{ $row['iso'] }}" title="{{ $row['full_time'] }}">{{ $row['time'] }}</time>
                            </span>
                            <span class="el-row-sub">
                                <span class="el-sr">{{ $row['app'] }} · {{ $row['device'] }} · </span>
                                <span class="el-row-meta">{{ $row['meta'] }}</span>
                                @if ($row['status'] !== null)
                                    @include('filament.library.status', ['status' => $row['status']])
                                @endif
                            </span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        @if (count($recordings) >= 100)
            <a class="el-text-btn" href="{{ $libraryUrl }}" style="margin-top: var(--space-3)">See them all in the Library</a>
        @endif
    @endif
</div>
