{{--
    The chosen recording in a drawer on the right (since v1.6, P4.4; beside the list since v1.5): where it came from,
    its title, when and how long, its project, language and labels, what needs you, the actions (Open, Send to
    WhatsApp, Copy text: the whole transcript) and the start of its transcript, in the view it opens in. Esc or the close button closes it; the list stays where it
    was. Below 1024 px a row opens the recording page instead.
--}}
@if ($detail === null)
    <div class="el-drawer-slot" wire:key="library-detail-none" hidden></div>
@else
    @php
        $row = \App\Filament\Support\RecordingRow::for($detail);
        $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
        $final = $detail->finalTranscript;
        $completed = $final?->status === \App\Enums\TranscriptStatus::Completed;
        // The view the recording opens in (Roman Urdu for Urdu, unless Settings says otherwise) once it is made.
        $view = $completed ? \App\Services\Sharing\WhatsAppMessage::defaultView($detail) : null;
        $lines = $completed ? app(\App\Services\TranscriptExporter::class)->lines($final, $detail, $view) : [];
        $turns = [];
        foreach (array_slice($lines, 0, 40) as $line) {
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['name'] === $line['speaker']) {
                $turns[$last]['text'] .= ' '.$line['text'];
            } else {
                $turns[] = ['name' => $line['speaker'], 'channel' => $line['channel'] === 2 ? 2 : 1,
                    'at' => \App\Services\ClientAi\ClientWorkspace::clock($line['start_ms']), 'text' => $line['text']];
            }
        }
        $segments = $lines;
        // Copy text copies the whole transcript in that view, as on the recording page.
        $fullText = $completed ? \App\Services\Sharing\WhatsAppMessage::transcriptText($detail, $view) : null;
        $url = \App\Filament\Resources\CaptureSessions\CaptureSessionResource::getUrl('view', ['record' => $detail]);
        $time = \App\Filament\Support\Shell::timeFormat();
    @endphp
    <div class="el-drawer-slot" wire:key="library-detail-{{ $detail->id }}"
        x-data x-on:keydown.escape.window="if (! document.querySelector('.fi-modal-open')) $wire.closeRecording()">
        <div class="el-drawer-backdrop" wire:click="closeRecording" aria-hidden="true"></div>
        <aside class="el-drawer" role="dialog" aria-modal="false" aria-labelledby="el-drawer-title" data-recording-drawer="{{ $detail->id }}">
            <header class="el-drawer-top">
                <span class="el-drawer-source">@include('filament.library.tile', ['row' => $row]) <span>{{ $row['app'] }} · {{ $row['device'] }}</span></span>
                <button type="button" class="el-icon-btn" wire:click="closeRecording" aria-label="Close (Esc)" title="Close (Esc)">@svg('heroicon-o-x-mark', ['aria-hidden' => 'true'])</button>
            </header>
            <div class="el-detail-head">
                <h2 class="el-detail-title" id="el-drawer-title">{{ $row['title'] }}</h2>
                <p class="el-meta">
                    {{ $detail->started_at->copy()->setTimezone($zone)->format('M j · '.$time) }}@if ($detail->duration_ms) · {{ \App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable::duration($detail->duration_ms) }}@endif
                    @if ($detail->client) · <a class="el-link" href="{{ \App\Filament\Resources\Clients\ClientResource::getUrl('view', ['record' => $detail->client]) }}">{{ $detail->client->name }}</a>@endif
                </p>
                <div class="el-chips">
                    <span class="el-chip el-chip-static @if ($detail->client) el-on @endif">@svg($detail->client ? 'heroicon-s-folder' : 'heroicon-o-folder', ['aria-hidden' => 'true'])<span>{{ $detail->client?->name ?? 'No project' }}</span></span>
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
                    @if (! \App\Services\Workspaces\ConsoleAccess::readOnly())
                        {{ $this->drawerWhatsappAction }}
                    @endif
                    @if ($fullText !== null)
                        <button type="button" class="el-pill" x-data="{ done: false, text: @js($fullText) }"
                            x-on:click="navigator.clipboard.writeText(text).then(() => { done = true; setTimeout(() => done = false, 2000) })">
                            @svg('heroicon-o-clipboard-document', ['aria-hidden' => 'true'])
                            <span x-text="done ? 'Copied' : 'Copy text'">Copy text</span>
                        </button>
                    @endif
                </div>
            </div>
            <div class="el-detail-body">
                @if ($turns === [])
                    <p class="el-muted">No transcript yet.</p>
                @else
                    <p class="el-caption" style="margin-bottom: var(--space-3)">Transcript{{ $view !== null ? ' · '.\App\Services\Views\TranscriptViews::VIEWS[$view] : '' }}{{ count($segments) > 40 ? ' · the first 40 lines' : '' }}</p>
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
        </aside>
    </div>
@endif
