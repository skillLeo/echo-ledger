@php
    $labels = ['original' => 'Original from the engine', 'edit' => 'Edited', 'replace' => 'Find and replace or vocabulary', 'restore' => 'Original restored'];
    $tz = app(\App\Services\SettingsRepository::class)->displayTimezone();
@endphp
<div class="el-history">
    <style>
        .el-history { font-size: .875rem; display: grid; gap: .5rem; }
        .el-history .el-rev { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; padding: .5rem .75rem; border-radius: .6rem; border: 1px solid rgba(15,23,42,.12); }
        .dark .el-history .el-rev { border-color: rgba(255,255,255,.12); }
        .el-history .el-muted { color: #475569; } .dark .el-history .el-muted { color: #CBD5E1; }
        .el-history strong { font-weight: 600; }
    </style>
    @forelse ($revisions as $revision)
        <div class="el-rev">
            <strong>{{ $labels[$revision->source] ?? $revision->source }}</strong>
            <span class="el-muted">{{ $revision->created_at->copy()->setTimezone($tz)->format('M j, Y · g:i A') }}</span>
            @if ($revision->author)
                <span class="el-muted">by {{ $revision->author }}</span>
            @endif
        </div>
    @empty
        <p class="el-muted">This transcript has not been edited. Edits, find and replace and vocabulary corrections appear here, and the original can always be restored.</p>
    @endforelse
</div>
