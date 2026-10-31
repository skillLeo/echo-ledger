{{--
    The recording's header chips, as the phone's Organise: the client it is filed under, its language (choose another
    to transcribe it again), its labels, Pin and Keep forever. Below them a banner only when something needs the person.
--}}
@php
    $session = $getRecord();
    $final = $session->finalTranscript;
    $language = \App\Stt\LanguageChoice::label($final->language ?? $session->language);
    $canRetranscribe = $session->primaryRecording?->isReady() === true && ! $session->hasNoAudio();
    $labels = $session->labelList();
@endphp
<div class="el-recording-chips" style="display: flex; flex-direction: column; gap: var(--space-3)">
    <div class="el-chips" role="group" aria-label="Organise">
        <button type="button" @class(['el-chip', 'el-on' => $session->client !== null]) wire:click="mountAction('fileUnder')"
            aria-label="Client: {{ $session->client?->name ?? 'none' }}. Change">
            @svg('heroicon-o-user', ['aria-hidden' => 'true'])<span>{{ $session->client?->name ?? 'No client' }}</span>@svg('heroicon-m-chevron-down', ['aria-hidden' => 'true', 'style' => 'width: 14px; height: 14px'])
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
                @svg('heroicon-o-tag', ['aria-hidden' => 'true'])<span>{{ $label }}</span>
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

    @if ($session->hasNoAudio())
        <div class="el-banner">
            @svg('heroicon-o-speaker-x-mark', ['aria-hidden' => 'true'])
            <p class="el-banner-body"><strong>No audio.</strong> Nothing was heard in this recording, so there is nothing to play or transcribe. On the phone run Setup → Call capture → Test call capture; then delete this recording.</p>
        </div>
    @elseif ($final?->status === \App\Enums\TranscriptStatus::Failed)
        <div class="el-banner el-banner-danger" role="alert">
            @svg('heroicon-o-exclamation-circle', ['aria-hidden' => 'true'])
            <p class="el-banner-body"><strong>Transcription failed.</strong> {{ $final->error ?? 'Try again.' }}</p>
            @if ($session->primaryRecording?->isReady())
                <button type="button" class="el-text-btn" wire:click="mountAction('retry')">Transcribe again</button>
            @endif
        </div>
    @endif
</div>
