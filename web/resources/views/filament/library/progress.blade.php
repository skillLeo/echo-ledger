{{--
    Clear progress (since v1.5, Phase 1C): Upload · Transcribing · Improving with their real numbers. `compact` is the
    Library row's one line (the step that is moving, with its numbers); otherwise the three steps with a bar each.
--}}
@php($compact = $compact ?? false)
@if ($compact)
    @php($step = collect($progress['steps'])->firstWhere('key', $progress['active']))
    <span class="el-steps-compact" role="status" aria-label="{{ collect($progress['steps'])->map(fn ($s) => $s['label'].': '.($s['state'] === 'done' ? 'done' : ($s['state'] === 'active' ? ($s['detail'] ?? 'in progress') : 'waiting')))->implode(', ') }}">
        <span class="el-steps-dots" aria-hidden="true">
            @foreach ($progress['steps'] as $s)
                <span class="el-steps-dot" data-state="{{ $s['state'] }}"></span>
            @endforeach
        </span>
        <span class="el-steps-text">{{ $progress['summary'] }}</span>
    </span>
@else
    <ol class="el-steps" aria-label="Progress">
        @foreach ($progress['steps'] as $s)
            <li class="el-step" data-state="{{ $s['state'] }}">
                <span class="el-step-head">
                    <span class="el-step-mark" aria-hidden="true">
                        @if ($s['state'] === 'done')
                            @svg('heroicon-m-check', ['aria-hidden' => 'true'])
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    <span class="el-step-label">{{ $s['label'] }}</span>
                    <span class="el-sr">: {{ $s['state'] === 'done' ? 'done' : ($s['state'] === 'active' ? 'in progress' : 'waiting') }}</span>
                </span>
                <span class="el-step-detail">{{ $s['state'] === 'active' ? ($s['detail'] ?? 'In progress') : ($s['state'] === 'done' ? 'Done' : ($s['detail'] ?? 'Next')) }}</span>
                <span class="el-step-bar" @if ($s['state'] === 'active' && $s['percent'] !== null) role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $s['percent'] }}" aria-label="{{ $s['label'] }}" @endif>
                    <span class="el-step-fill" @if ($s['state'] === 'active' && $s['percent'] === null) data-indeterminate @endif
                        style="width: {{ $s['state'] === 'done' ? 100 : ($s['state'] === 'active' ? ($s['percent'] ?? 30) : 0) }}%"></span>
                </span>
            </li>
        @endforeach
    </ol>
@endif
