@php
    $labels = ['viewed' => 'Opened', 'wrong_password' => 'Wrong password', 'audio' => 'Played the recording'];
@endphp
<div class="el-history">
    <style>
        .el-history { font-size: .875rem; display: grid; gap: .4rem; }
        .el-history .el-rev { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; padding: .45rem .7rem; border-radius: .6rem; border: 1px solid rgba(15,23,42,.12); }
        .dark .el-history .el-rev { border-color: rgba(255,255,255,.12); }
        .el-history .el-muted { color: #475569; overflow-wrap: anywhere; } .dark .el-history .el-muted { color: #CBD5E1; }
    </style>
    @forelse ($views as $view)
        <div class="el-rev">
            <strong>{{ $labels[$view->outcome] ?? $view->outcome }}</strong>
            <span class="el-muted">{{ $view->viewed_at?->copy()->setTimezone($tz)->format('M j, Y · g:i:s A') }}</span>
            <span class="el-muted">{{ \Illuminate\Support\Str::limit((string) $view->user_agent, 90) }}</span>
            @if ($view->ip_hash)
                <span class="el-muted" title="A keyed hash of the address, not the address itself">visitor {{ substr($view->ip_hash, 0, 8) }}</span>
            @endif
        </div>
    @empty
        <p class="el-muted">Nobody has opened this link yet.</p>
    @endforelse
</div>