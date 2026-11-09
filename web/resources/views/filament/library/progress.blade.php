{{--
    Clear progress (since v1.5, Phase 1C; the phone's and the desktop's words since v1.6, P5.4): Upload · Transcribe ·
    Improve, each named for its state (Uploading, Uploaded…). `compact` is the Library row's one line ("Transcribing ·
    part 2 of 3 · about 20 s left"); otherwise the three steps, the same line under them and one bar.
--}}
@php($compact = $compact ?? false)
@php($state = fn (array $s): string => $s['state'] === 'done' ? 'done' : ($s['state'] === 'active' ? 'in progress' : 'waiting'))
@if ($compact)
    <span class="el-steps-compact" role="status" aria-label="{{ collect($progress['steps'])->map(fn ($s) => $s['label'].': '.$state($s))->implode(', ') }}. {{ $progress['summary'] }}">
        <span class="el-steps-dots" aria-hidden="true">
            @foreach ($progress['steps'] as $s)
                <span class="el-steps-dot" data-state="{{ $s['state'] }}"></span>
            @endforeach
        </span>
        <span class="el-steps-text">{{ $progress['summary'] }}</span>
    </span>
@else
    @php($current = collect($progress['steps'])->firstWhere('key', $progress['active']))
    <ol class="el-steps" aria-label="Progress">
        @foreach ($progress['steps'] as $s)
            <li class="el-step" data-state="{{ $s['state'] }}" data-step="{{ $s['key'] }}" @if ($s['state'] === 'active') aria-current="step" @endif>
                <span class="el-step-mark" aria-hidden="true">
                    @if ($s['state'] === 'done')
                        @svg('heroicon-m-check', ['aria-hidden' => 'true'])
                    @else
                        {{ $loop->iteration }}
                    @endif
                </span>
                <span class="el-step-label">{{ $s['label'] }}</span>
                <span class="el-sr">: {{ $state($s) }}</span>
            </li>
        @endforeach
    </ol>
    <span class="el-steps-line">{{ $progress['summary'] }}</span>
    <span class="el-step-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $progress['summary'] }}"
        @if ($current['percent'] !== null) aria-valuenow="{{ $current['percent'] }}" @endif>
        <span class="el-step-fill" @if ($current['percent'] === null) data-indeterminate @endif style="width: {{ $current['percent'] ?? 30 }}%"></span>
    </span>
@endif
