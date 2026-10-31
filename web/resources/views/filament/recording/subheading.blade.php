<span class="el-subhead">
    <span class="el-meta el-meta-app">
        <span class="el-tile el-tile-{{ $row['tile'] }}" style="width: 22px; height: 22px; border-radius: 999px" aria-hidden="true">@svg($row['icon'], ['style' => 'width: 13px; height: 13px'])</span>
        {{ $row['app'] }} · {{ $row['device'] }}
    </span>
    <span class="el-meta">{{ $when }}</span>
</span>
