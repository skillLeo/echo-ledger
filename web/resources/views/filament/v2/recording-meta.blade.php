{{-- Under the recording's title (v2.0): the source, who it was with, when and how long, and one status. --}}
@php
    $zone = app(\App\Services\SettingsRepository::class)->displayTimezone();
    $at = $session->started_at->copy()->setTimezone($zone)->locale(app()->getLocale());
    $final = $session->finalTranscript;
    $ready = $final?->status === \App\Enums\TranscriptStatus::Completed;
@endphp
<span class="v2-rec-meta">
    <x-el.v2.icon :name="$row['icon']" />
    <span><bdi>{{ $row['line'] }}</bdi></span>
    <span aria-hidden="true">·</span>
    <span>{{ $at->translatedFormat('j M, '.(\App\Filament\Support\Shell::timeFormat() === 'H:i' ? 'H:i' : 'g:i a')) }}</span>
    @if ($row['length'] !== '')
        <span aria-hidden="true">·</span>
        <span class="v2-mono">{{ $row['length'] }}</span>
    @endif
    @if ($row['status'] !== null)
        <span @class(['v2-status', 'is-'.$row['status']['tone']])><x-el.v2.icon :name="$row['status']['icon']" />{{ $row['status']['label'] }}</span>
    @elseif ($ready)
        <span class="v2-status is-success"><x-el.v2.icon name="check" />{{ __('Transcribed') }}</span>
    @endif
</span>
