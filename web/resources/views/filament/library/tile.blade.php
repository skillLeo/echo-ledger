{{-- The app tile with the device badge; the names are said once, beside it, for screen readers. --}}
<span class="el-tile el-tile-{{ $row['tile'] }}" title="{{ $row['app'] }} · {{ $row['device'] }}" aria-hidden="true">
    @svg($row['icon'])
    <span class="el-tile-badge">@svg($row['device_icon'])</span>
</span>
