{{-- The v2.0 Home: today's words in the new design. The four points are rows, never a grid of identical cards. --}}
@extends('site.v2.layout')

@section('title', \App\Support\Brand::name().' · calls and meetings, written down')

@section('content')
    <section class="s-hero" aria-labelledby="s-hero-title">
        <h1 id="s-hero-title" class="s-display">Your calls and meetings, written down.</h1>
        <p class="s-lead">
            @brand records calls and meetings on your phone and computer, both sides of the conversation, and writes
            them down as transcripts you can trust, in English, Urdu and Roman Urdu. Everything is kept in projects you
            can search, share with your team, and open in Claude or ChatGPT.
        </p>
        <p class="s-actions">
            <a class="s-btn s-btn-primary" href="{{ url('/app') }}">Sign in</a>
            @if ($hasBuilds)
                <a class="s-btn s-btn-secondary" href="{{ route('downloads', \App\Support\WebsiteV2::keep(request())) }}">Get the apps</a>
            @endif
        </p>
    </section>

    <section class="s-points" aria-label="What it does">
        @foreach ([
            ['phone', "Calls that don't disturb you", 'Recording starts when the call is answered. Unanswered and very short calls are skipped by themselves, with nothing to tap afterwards.'],
            ['transcript', 'Urdu and Roman Urdu', 'Urdu and mixed calls wait for the engine made for them, and the Roman Urdu view shows the same call in Latin letters.'],
            ['folder', 'Projects and sharing', 'Recordings file themselves into projects by contact, meeting title or app. Share a recording or a whole project with a link, a password, or only the people you name.'],
            ['captions', 'What was on the screen', 'During a meeting the computer app keeps what was on the shared screen, linked to the moment it was talked about.'],
        ] as [$icon, $title, $text])
            <div class="s-point">
                <x-el.v2.icon :name="$icon" class="s-point-icon" />
                <h2 class="s-point-title">{{ $title }}</h2>
                <p class="s-point-text">{{ $text }}</p>
            </div>
        @endforeach
    </section>

    <section class="s-band" aria-labelledby="s-ask">
        <h2 id="s-ask" class="s-title">Ask about a recording, in your own AI</h2>
        <p class="s-text">Connect @brand to Claude or ChatGPT and ask about any call or project, with the transcript and the screens it had.</p>
    </section>
@endsection
