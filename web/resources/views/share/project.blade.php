@extends('share.layout')

@section('title', $project->name)

@section('content')
    @php($zone = app(\App\Services\SettingsRepository::class)->displayTimezone())
    <div class="card">
        <h1 dir="auto">{{ $project->name }}</h1>
        <p class="facts">
            {{ $sessions->count() === 1 ? '1 recording' : $sessions->count().' recordings' }}
            @if ($expiresAt) · link expires {{ $expiresAt->copy()->setTimezone($zone)->format('j M Y, H:i T') }} @endif
        </p>
        @if ($sessions->isEmpty())
            <p class="muted">There are no recordings in this project yet.</p>
        @else
            <ul class="list">
                @foreach ($sessions as $session)
                    <li>
                        <a href="{{ url('/p/'.$token.'/'.$session->id) }}" dir="auto">{{ $session->displayTitle() }}</a>
                        <span class="muted">
                            {{ $session->started_at->copy()->setTimezone($zone)->format('j M Y, H:i') }}
                            @if ($session->duration_ms) · {{ \App\Services\TranscriptExporter::clock($session->duration_ms) }} @endif
                            @if ($session->client_id !== $project->id && isset($folders[$session->client_id])) · {{ $folders[$session->client_id] }} @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
