@extends('share.layout')

@section('title', $session->displayTitle())

@section('content')
    <div class="card" @if ($languageCode ?? null) lang="{{ $languageCode }}" @endif>
        <h1 dir="auto">{{ $session->displayTitle() }}</h1>
        <p class="facts">
            {{ $session->started_at->copy()->setTimezone(app(\App\Services\SettingsRepository::class)->displayTimezone())->format('j M Y, H:i T') }}
            @if ($session->duration_ms) · {{ \App\Services\TranscriptExporter::clock($session->duration_ms) }} @endif
            @if ($expiresAt) · link expires {{ $expiresAt->copy()->setTimezone(app(\App\Services\SettingsRepository::class)->displayTimezone())->format('j M Y, H:i T') }} @endif
        </p>
        @if ($audio)
            <audio controls preload="metadata" src="{{ url('/s/'.$token.'/audio') }}"></audio>
        @endif
        @if ($transcribing)
            <p class="muted">The transcript is not ready yet. Open this link again later.</p>
        @elseif ($lines === [])
            <p class="muted">No speech was found in this recording.</p>
        @else
            @if ($viewLabel ?? null)
                <p class="muted">{{ $viewLabel }} view, written by Claude from the transcript. The transcript itself is unchanged.</p>
            @endif
            @foreach ($lines as $line)
                <p class="line" dir="auto"><span class="at">{{ \App\Services\TranscriptExporter::clock($line['start_ms']) }}</span><span class="who spk-{{ $line['channel'] === 2 ? 2 : 1 }}">{{ $line['speaker'] }}</span><span>{{ $line['text'] }}</span></p>
            @endforeach
        @endif
    </div>
@endsection
