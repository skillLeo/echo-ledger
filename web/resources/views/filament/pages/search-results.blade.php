@php
    $mark = function (string $snippet, array $ranges): string {
        $out = ''; $pos = 0;
        foreach ($ranges as [$start, $length]) {
            if ($start < $pos) { continue; }
            $out .= e(mb_substr($snippet, $pos, $start - $pos)).'<mark>'.e(mb_substr($snippet, $start, $length)).'</mark>';
            $pos = $start + $length;
        }

        return $out.e(mb_substr($snippet, $pos));
    };
@endphp
<div class="el-search">
    <style>
        .el-search { display: grid; gap: .75rem; font-size: .875rem; }
        .el-search .el-muted { color: #475569; } .dark .el-search .el-muted { color: #CBD5E1; }
        .el-search .el-card { border: 1px solid rgba(15,23,42,.12); border-radius: .9rem; padding: .85rem 1rem; background: rgba(255,255,255,.6); }
        .dark .el-search .el-card { border-color: rgba(255,255,255,.1); background: rgba(255,255,255,.03); }
        .el-search .el-title { font-weight: 600; font-size: .95rem; text-decoration: none; color: inherit; overflow-wrap: anywhere; }
        .el-search .el-title:hover { text-decoration: underline; }
        .el-search .el-hits { display: grid; gap: .35rem; margin-top: .5rem; }
        .el-search .el-hit { display: grid; grid-template-columns: 4rem minmax(0, 1fr); gap: .5rem; text-decoration: none; color: inherit; padding: .3rem .4rem; border-radius: .5rem; }
        .el-search .el-hit:hover { background: rgba(139,92,246,.1); }
        .el-search .el-hit .el-t { font-variant-numeric: tabular-nums; }
        .el-search .el-snip { overflow-wrap: anywhere; unicode-bidi: plaintext; }
        .el-search mark { background: rgba(250,204,21,.45); color: inherit; border-radius: 3px; padding: 0 .1rem; }
        .el-search .el-pager { display: flex; gap: .5rem; justify-content: flex-end; }
        .el-search button { font: inherit; padding: .35rem .8rem; border-radius: .5rem; border: 1px solid rgba(15,23,42,.18); background: transparent; color: inherit; cursor: pointer; }
        .dark .el-search button { border-color: rgba(255,255,255,.2); }
    </style>
    @if (mb_strlen($query) < 2)
        <p class="el-muted">Type at least two characters. Every word must match; filters narrow the results.</p>
    @elseif ($items->isEmpty())
        <p class="el-muted">Nothing found for “{{ $query }}”. Try fewer words or other filters.</p>
    @else
        @foreach ($items as $session)
            @php($found = $hits[$session->id] ?? ['hits' => [], 'count' => 0])
            <div class="el-card">
                <a class="el-title" href="{{ $urlFor($session) }}" dir="auto">{{ $session->displayTitle() }}</a>
                <div class="el-muted">
                    {{ $session->started_at->copy()->setTimezone($tz)->format('M j, Y · g:i A') }} · {{ $session->source_app->getLabel() }}
                    @if ($session->client) · {{ $session->client->name }} @endif
                    @if ($found['count'] > count($found['hits'])) · {{ $found['count'] }} matches @endif
                </div>
                @if ($found['hits'] !== [])
                    <div class="el-hits">
                        @foreach ($found['hits'] as $hit)
                            <a class="el-hit" href="{{ $urlFor($session, $hit['start_ms']) }}" aria-label="Play {{ $session->displayTitle() }} from {{ \App\Services\TranscriptExporter::clock($hit['start_ms']) }}">
                                <span class="el-t el-muted">{{ \App\Services\TranscriptExporter::clock($hit['start_ms']) }}</span>
                                <span class="el-snip" dir="auto"><strong>{{ $hit['speaker'] }}:</strong> {!! $mark($hit['snippet'], $hit['highlights']) !!}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
        <div class="el-pager">
            @if ($page > 1)<button type="button" wire:click="previousPage">Newer</button>@endif
            @if ($more)<button type="button" wire:click="nextPage">Older</button>@endif
        </div>
    @endif
</div>
