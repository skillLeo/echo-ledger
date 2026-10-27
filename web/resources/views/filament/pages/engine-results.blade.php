@php
    $statusLabels = ['pending' => 'Waiting', 'submitted' => 'Sent', 'processing' => 'Working', 'completed' => 'Done', 'failed' => 'Failed'];
@endphp
<div class="el-engines">
    <style>
        .el-engines { display: grid; gap: 1rem; font-size: .875rem; }
        .el-engines .el-cols { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .el-engines .el-col { border: 1px solid rgba(15,23,42,.12); border-radius: .75rem; padding: .75rem; display: grid; gap: .5rem; align-content: start; }
        .dark .el-engines .el-col { border-color: rgba(255,255,255,.12); }
        .el-engines .el-head { display: flex; flex-wrap: wrap; gap: .25rem .5rem; align-items: baseline; }
        .el-engines .el-muted { color: #475569; } .dark .el-engines .el-muted { color: #CBD5E1; }
        .el-engines .el-warn { color: #B45309; font-weight: 600; } .dark .el-engines .el-warn { color: #FBBF24; }
        .el-engines .el-text { white-space: pre-wrap; max-height: 28rem; overflow: auto; line-height: 1.6; unicode-bidi: plaintext; }
        .el-engines .el-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .el-engines button { border: 1px solid rgba(15,23,42,.18); border-radius: .5rem; padding: .3rem .65rem; font-weight: 600; }
        .dark .el-engines button { border-color: rgba(255,255,255,.2); }
    </style>
    @if ($runs === [])
        <p class="el-muted">No engine runs yet. Use “Compare engines” first.</p>
    @else
        <p class="el-muted">Read the same passage in each column. Pick the most accurate one as the first engine for its language; new recordings in that language then use it.</p>
        <div class="el-cols">
            @foreach ($runs as $run)
                <section class="el-col" aria-label="{{ $run['label'] }}">
                    <div class="el-head">
                        <strong>{{ $run['label'] }}</strong>
                        @if ($run['model'])<span class="el-muted">{{ $run['model'] }}</span>@endif
                        <span class="el-muted">· {{ $run['language'] }} · {{ $statusLabels[$run['status']] ?? $run['status'] }}</span>
                    </div>
                    <div class="el-muted">
                        @if ($run['cost_usd'] !== null)
                            Cost: US$ {{ number_format($run['cost_usd'], 4) }} ({{ $run['minutes'] }} billed min)
                        @else
                            Cost: not known yet
                        @endif
                    </div>
                    @if ($run['script_warning'])
                        <div class="el-warn">Wrong script detected: mostly {{ $run['script_warning'] }}</div>
                    @endif
                    @if ($run['error'])
                        <div class="el-warn">{{ $run['error'] }}</div>
                    @endif
                    @if ($run['status'] === 'completed')
                        <div class="el-text" dir="auto">{{ $run['text'] }}</div>
                        <div class="el-actions">
                            <button type="button" wire:click="pickEngine('{{ $run['id'] }}')">Make default for {{ $run['language'] }}</button>
                            <button type="button" wire:click="useEngineRun('{{ $run['id'] }}')">Use this version</button>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
