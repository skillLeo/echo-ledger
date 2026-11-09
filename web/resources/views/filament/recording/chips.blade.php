{{--
    The recording's header chips, as the phone's Organise: the project it is filed under, its language (choose another
    to transcribe it again), its labels, Pin and Keep forever. Below them a banner only when something needs the person.
--}}
@php
    $session = $getRecord();
    $final = $session->finalTranscript;
    $language = \App\Stt\LanguageChoice::label($final->language ?? $session->language);
    $canRetranscribe = $session->primaryRecording?->isReady() === true && ! $session->hasNoAudio();
    $labels = $session->labelList();
    // Since v1.5: an Urdu or Auto recording written by a lower engine of its list (the first one stayed busy or refused it).
    $backup = $final?->status === \App\Enums\TranscriptStatus::Completed ? \App\Services\EngineWait::fields($final) : null;
    $first = \App\Enums\SttProviderKey::tryFrom((string) ($final?->meta['route']['tried'][0]['engine'] ?? ''))?->getLabel() ?? 'The first engine for this language';
    $writer = $final?->providerKey()?->getLabel() ?? 'another engine';
    // Since v1.5 members and viewers read the console only: the chips say what is set, and change nothing.
    $readOnly = \App\Services\Workspaces\ConsoleAccess::readOnly();
@endphp
<div class="el-recording-chips" style="display: flex; flex-direction: column; gap: var(--space-3)">
    @if ($readOnly)
        <div class="el-chips" role="group" aria-label="Organise">
            <span class="el-chip el-chip-static">@svg('heroicon-o-folder', ['aria-hidden' => 'true'])<span>{{ $session->client?->name ?? 'No project' }}</span></span>
            <span class="el-chip el-chip-static">@svg('heroicon-o-language', ['aria-hidden' => 'true'])<span>{{ $language }}</span></span>
            @foreach ($labels as $label)
                <span class="el-chip el-chip-static el-on">@svg('heroicon-s-tag', ['aria-hidden' => 'true'])<span>{{ $label }}</span></span>
            @endforeach
        </div>
    @else
        <div class="el-chips" role="group" aria-label="Organise">
            <button type="button" @class(['el-chip', 'el-on' => $session->client !== null]) wire:click="mountAction('fileUnder')"
                aria-label="Project: {{ $session->client?->name ?? 'none' }}. Change">
                @svg($session->client !== null ? 'heroicon-s-folder' : 'heroicon-o-folder', ['aria-hidden' => 'true'])<span>{{ $session->client?->name ?? 'No project' }}</span>@svg('heroicon-m-chevron-down', ['aria-hidden' => 'true', 'style' => 'width: 14px; height: 14px'])
            </button>
            @if ($canRetranscribe)
                <button type="button" class="el-chip" wire:click="mountAction('retranscribe_as')" aria-label="Language: {{ $language }}. Transcribe again in another language">
                    @svg('heroicon-o-language', ['aria-hidden' => 'true'])<span>{{ $language }}</span>@svg('heroicon-m-chevron-down', ['aria-hidden' => 'true', 'style' => 'width: 14px; height: 14px'])
                </button>
            @else
                <span class="el-chip el-chip-static">@svg('heroicon-o-language', ['aria-hidden' => 'true'])<span>{{ $language }}</span></span>
            @endif
            @foreach ($labels as $label)
                <button type="button" class="el-chip el-on" wire:click="mountAction('labels')" aria-label="Label {{ $label }}. Edit labels">
                    @svg('heroicon-s-tag', ['aria-hidden' => 'true'])<span>{{ $label }}</span>
                </button>
            @endforeach
            <button type="button" class="el-chip" wire:click="mountAction('labels')">
                @svg('heroicon-o-plus', ['aria-hidden' => 'true'])<span>{{ $labels === [] ? 'Labels' : 'Label' }}</span>
            </button>
            <button type="button" class="el-chip" wire:click="togglePin" aria-pressed="{{ $session->pinned ? 'true' : 'false' }}">
                @svg($session->pinned ? 'heroicon-s-star' : 'heroicon-o-star', ['aria-hidden' => 'true'])<span>{{ $session->pinned ? 'Pinned' : 'Pin' }}</span>
            </button>
            <button type="button" class="el-chip" wire:click="toggleKeep" aria-pressed="{{ $session->keep_forever ? 'true' : 'false' }}"
                title="Kept recordings are never deleted automatically">
                @svg($session->keep_forever ? 'heroicon-s-lock-closed' : 'heroicon-o-lock-open', ['aria-hidden' => 'true'])<span>Keep forever</span>
            </button>
        </div>
    @endif

    {{-- Since v1.6.1 these are notices about the recording itself: ×, swipe, no timer, closed until it changes. --}}
    @if ($session->hasNoAudio())
        <x-notice id="no-audio-{{ $session->id }}" state="no-audio" class="el-banner">
            @svg('heroicon-o-speaker-x-mark', ['aria-hidden' => 'true'])
            <p class="el-banner-body"><strong>No audio.</strong> Nothing was heard in this recording, so there is nothing to play or transcribe. On the phone run Setup → Call capture → Test call capture; then delete this recording.</p>
        </x-notice>
    @elseif ($final?->status === \App\Enums\TranscriptStatus::Failed)
        <x-notice id="transcript-failed-{{ $session->id }}" :state="$final->id.':'.($final->updated_at?->getTimestamp() ?? '')" class="el-banner el-banner-danger" role="alert">
            @svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true'])
            <p class="el-banner-body"><strong>Transcription failed.</strong> {{ $final->error ?? 'Try again.' }}</p>
            @if ($session->primaryRecording?->isReady() && ! $readOnly)
                <button type="button" class="el-text-btn" wire:click="mountAction('retry')">Transcribe again</button>
            @endif
        </x-notice>
    @elseif ($backup !== null && $backup['backup_engine'])
        <x-notice id="backup-engine-{{ $session->id }}" :state="$final?->id ?? ''" class="el-banner" data-backup-engine>
            @svg('heroicon-o-arrow-path', ['aria-hidden' => 'true'])
            <p class="el-banner-body"><strong>Backup engine — Re-transcribe?</strong>
                {{ $backup['backup_reason'] === 'rejected'
                    ? $first.' could not take this recording, so '.$writer.' wrote this transcript.'
                    : $first.' stayed busy, so '.$writer.' wrote this transcript.' }}</p>
            @if ($canRetranscribe && ! $readOnly)
                <button type="button" class="el-text-btn" wire:click="mountAction('retranscribeBackup')">Re-transcribe</button>
            @endif
        </x-notice>
    @endif
</div>
