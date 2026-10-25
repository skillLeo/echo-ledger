@php
    $clock = fn (int $ms): string => \App\Services\TranscriptExporter::clock($ms);
    $label = fn (?\App\Models\Transcript $t): string => $t === null ? '—'
        : (\App\Enums\SttProviderKey::tryFrom($t->provider)?->getLabel() ?? $t->provider).(\App\Http\Resources\TranscriptResource::model($t) ? ' · '.\App\Http\Resources\TranscriptResource::model($t) : '');
@endphp
<div class="el-compare">
    <style>
        .el-compare { font-size: .875rem; }
        .el-compare .el-muted { color: #475569; } .dark .el-compare .el-muted { color: #CBD5E1; }
        .el-compare .el-grid { display: grid; grid-template-columns: 4rem minmax(0, 1fr) minmax(0, 1fr); gap: .5rem 1rem; }
        .el-compare .el-h { font-weight: 600; }
        .el-compare .el-t { font-variant-numeric: tabular-nums; }
        .el-compare .el-txt { line-height: 1.6; overflow-wrap: anywhere; unicode-bidi: plaintext; }
        .el-compare mark { background: rgba(250,204,21,.45); color: inherit; border-radius: 3px; padding: 0 .1rem; }
        .dark .el-compare mark { background: rgba(250,204,21,.35); }
        .el-compare .el-err { color: #E11D48; }
        @media (max-width: 720px) { .el-compare .el-grid { grid-template-columns: minmax(0, 1fr); } .el-compare .el-blank { display: none; } }
    </style>
    @if ($alternate === null)
        <p class="el-muted">There is no second version.</p>
    @elseif ($alternate->status !== \App\Enums\TranscriptStatus::Completed)
        <p>
            The second version ({{ $label($alternate) }}) is {{ str_replace('_', ' ', $alternate->status->value) }}.
            @if ($alternate->status === \App\Enums\TranscriptStatus::Failed && $alternate->error)
                <span class="el-err">{{ $alternate->error }}</span>
            @else
                Open this again when it is done.
            @endif
        </p>
    @elseif ($diff !== null)
        <p class="el-muted" style="margin-bottom: .75rem">
            {{ number_format($diff['words_a']) }} words in the current transcript, {{ number_format($diff['words_b']) }} in the second version.
            Words that differ are highlighted.
        </p>
        <div class="el-grid">
            <div class="el-blank"></div>
            <div class="el-h">Current: {{ $label($final) }}</div>
            <div class="el-h">Second: {{ $label($alternate) }}</div>
            @foreach ($diff['rows'] as $row)
                <div class="el-t el-muted">{{ $clock($row['start_ms']) }}</div>
                @foreach (['a', 'b'] as $side)
                    <div class="el-txt" dir="auto">@foreach ($row[$side] as [$w, $changed])@if ($changed)<mark>{{ $w }}</mark>@else{{ $w }}@endif {{-- --}}@endforeach</div>
                @endforeach
            @endforeach
        </div>
    @endif
</div>
