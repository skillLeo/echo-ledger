{{--
    The Library's detail pane on wide screens: the chosen recording's details and the start of its transcript, with
    Open for the player, editing and sharing. Hidden below 1280 px, where a row opens the recording page.
--}}
<aside class="el-library-detail" aria-label="Chosen recording" wire:key="library-detail-{{ $detail?->id ?? 'none' }}">
    @if ($detail === null)
        <div class="el-empty">
            <span class="el-empty-icon">@svg('heroicon-o-document-text', ['aria-hidden' => 'true'])</span>
            <p class="el-empty-title">Choose a recording</p>
            <p class="el-empty-text">Its transcript shows here. Open it to play, edit and share.</p>
        </div>
    @else
        @php
            $row = \App\Filament\Support\RecordingRow::for($detail);
            $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
            $final = $detail->finalTranscript;
            $segments = $final?->status === \App\Enums\TranscriptStatus::Completed ? ($final->segments ?? []) : [];
            $names = app(\App\Services\TranscriptEditor::class)->speakerNames($detail);
            $turns = [];
            foreach (array_slice($segments, 0, 40) as $segment) {
                $speaker = (string) ($segment['speaker'] ?? 'Speaker');
                $last = array_key_last($turns);
                if ($last !== null && $turns[$last]['speaker'] === $speaker) {
                    $turns[$last]['text'] .= ' '.$segment['text'];
                } else {
                    $turns[] = ['speaker' => $speaker, 'name' => $names[$speaker] ?? $speaker, 'channel' => (int) ($segment['channel'] ?? 1) === 2 ? 2 : 1,
                        'at' => \App\Services\ClientAi\ClientWorkspace::clock((int) ($segment['start_ms'] ?? 0)), 'text' => (string) ($segment['text'] ?? '')];
                }
            }
            $url = \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $detail]);
        @endphp
        <div class="el-detail-head">
            <div class="el-row" style="align-items: flex-start">
                @include('filament.library.tile', ['row' => $row])
                <div class="el-row-main">
                    <h2 class="el-detail-title">{{ $row['title'] }}</h2>
                    <p class="el-meta">{{ $row['app'] }} · {{ $row['device'] }}</p>
                </div>
                <button type="button" class="el-icon-btn" wire:click="closeRecording" aria-label="Close">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
            </div>
            <p class="el-meta">
                {{ $detail->started_at->copy()->setTimezone($zone)->format('M j · g:i A') }}@if ($detail->duration_ms) · {{ \App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable::duration($detail->duration_ms) }}@endif
            </p>
            <div class="el-chips">
                <span class="el-chip el-chip-static @if ($detail->client) el-on @endif">@svg('heroicon-o-user', ['aria-hidden' => 'true'])<span>{{ $detail->client?->name ?? 'No client' }}</span></span>
                <span class="el-chip el-chip-static">@svg('heroicon-o-language', ['aria-hidden' => 'true'])<span>{{ \App\Stt\LanguageChoice::label($final->language ?? $detail->language) }}</span></span>
                @foreach ($detail->labels as $label)
                    <span class="el-chip el-chip-static">@svg('heroicon-o-tag', ['aria-hidden' => 'true'])<span>{{ $label->label }}</span></span>
                @endforeach
            </div>
            @if ($row['status'] !== null)
                <div @class(['el-banner', 'el-banner-danger' => $row['status']['tone'] === 'danger'])>
                    @svg($row['status']['icon'], ['aria-hidden' => 'true'])
                    <span class="el-banner-body">
                        {{ $row['status']['label'] }}@if ($final?->error && $row['status']['tone'] === 'danger'): {{ $final->error }}@endif
                    </span>
                </div>
            @endif
            <div class="el-detail-actions">
                <a href="{{ $url }}" class="el-pill el-pill-primary" wire:navigate>@svg('heroicon-o-play', ['aria-hidden' => 'true']) Open</a>
            </div>
        </div>
        <div class="el-detail-body">
            @if ($turns === [])
                <p class="el-muted">No transcript yet.</p>
            @else
                <p class="el-caption" style="margin-bottom: var(--space-3)">Transcript{{ count($segments) > 40 ? ' · the first 40 lines' : '' }}</p>
                <div class="el-turns">
                    @foreach ($turns as $turn)
                        <div class="el-turn el-speaker-{{ $turn['channel'] }}">
                            <span class="el-avatar el-avatar-sm" aria-hidden="true">{{ mb_strtoupper(mb_substr($turn['name'], 0, 1)) }}</span>
                            <p class="el-turn-who">{{ $turn['name'] }} <time>{{ $turn['at'] }}</time></p>
                            <p class="el-turn-text" dir="auto">{{ $turn['text'] }}</p>
                        </div>
                    @endforeach
                </div>
                @if (count($segments) > 40)
                    <a href="{{ $url }}" class="el-text-btn" style="margin-top: var(--space-4)" wire:navigate>Open the whole transcript</a>
                @endif
            @endif
        </div>
    @endif
</aside>
