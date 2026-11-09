@extends('site.layout')

@section('title', 'EchoLedger · calls and meetings, written down')

@section('content')
    <section>
        <h1>Your calls and meetings, written down word for word.</h1>
        <p class="muted" style="max-width: 640px; font-size: 18px;">
            EchoLedger records calls and meetings on your phone and computer, both sides of the conversation, and turns
            them into accurate transcripts, in English, Urdu and Roman Urdu. Everything is kept in projects you can
            search, share with your team, and open in Claude or ChatGPT.
        </p>
        <p style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a class="button" href="{{ url('/app') }}">Sign in</a>
            @if ($hasBuilds)
                <a class="button secondary" href="{{ route('downloads') }}">Get the apps</a>
            @endif
        </p>
    </section>

    <section class="grid" style="margin-top: 32px;" aria-label="What it does">
        <div class="card">
            <h2>Calls that don't disturb you</h2>
            <p class="muted">Recording starts when the call is answered. Unanswered and very short calls are skipped by themselves, with nothing to tap afterwards.</p>
        </div>
        <div class="card">
            <h2>Urdu, accurately</h2>
            <p class="muted">Urdu and mixed calls wait for the most accurate engine, and the Roman Urdu view keeps every word.</p>
        </div>
        <div class="card">
            <h2>Projects and sharing</h2>
            <p class="muted">Recordings file themselves into projects by contact, meeting title or app. Share a recording or a whole project with a link, a password, or only the people you name.</p>
        </div>
        <div class="card">
            <h2>What was on the screen</h2>
            <p class="muted">During a meeting the computer app keeps what was on the shared screen, linked to the moment it was talked about.</p>
        </div>
    </section>

    <section class="card" style="margin-top: 32px;">
        <h2>Ask about a recording, in your own AI</h2>
        <p class="muted" style="margin: 0;">Connect EchoLedger to Claude or ChatGPT and ask about any call or project, with the transcript and the screens it had.</p>
    </section>
@endsection
