@extends('site.layout')

@section('title', 'Privacy Policy · EchoLedger')

@section('content')
    <div class="legal">
        @include('legal.draft')
        <h1>Privacy Policy</h1>
        <p class="muted">Last changed {{ \Illuminate\Support\Carbon::parse('2026-11-06')->format('F j, Y') }}</p>

        <h2>What we keep</h2>
        <ul>
            <li><strong>Your account:</strong> your name, email, password (as a one-way hash), two-step and passkey details, a linked Google account's id, and your settings.</li>
            <li><strong>Your recordings:</strong> the audio, encrypted on the server with a key that is not the server's own login key; transcripts, views, notes, screens and project files, stored with them.</li>
            <li><strong>Devices and sign-ins:</strong> the phones and computers signed in to your account, the browsers you sign in from, their addresses, and an audit log of sign-ins, shares, deletions and settings changes, kept for a year.</li>
            <li><strong>Usage:</strong> how many minutes each engine transcribed and what it cost, per workspace.</li>
        </ul>

        <h2>Who processes recordings</h2>
        <p>To transcribe and work with your recordings, the server sends them to the engines below. Which ones are used depends on the language and on what the server's operator set up. Each receives only what is listed, only for that task, under its own terms; the server asks each to delete its copy once it has answered, where the engine allows it.</p>
        <div class="table">
            <table>
                <thead><tr><th scope="col">Processor</th><th scope="col">What it receives</th><th scope="col">What for</th></tr></thead>
                <tbody>
                    <tr><td>AssemblyAI (US or EU, as set)</td><td>The audio of a recording, or the live audio while captions or dictation run; the vocabulary you added.</td><td>Transcripts, live captions, dictation.</td></tr>
                    <tr><td>Groq</td><td>The audio of a recording, in parts of up to ten minutes.</td><td>Transcripts (Whisper large-v3), mostly for Urdu.</td></tr>
                    <tr><td>Google Gemini (Google Cloud, paid tier)</td><td>The audio of a recording, uploaded for one request and deleted right after.</td><td>Transcripts, when the operator chose it for a language.</td></tr>
                    <tr><td>ElevenLabs</td><td>The audio of a recording, or the live audio while captions run.</td><td>Transcripts and live captions, when chosen.</td></tr>
                    <tr><td>Soniox</td><td>The audio of a recording; for Live Translate, the sound your phone plays, sent by the phone itself with a key valid for minutes.</td><td>Transcripts and Live Translate.</td></tr>
                    <tr><td>Microsoft Azure Speech</td><td>The audio of a recording, or the live audio while captions or dictation run.</td><td>Transcripts, live captions and dictation, when the operator chose it.</td></tr>
                    <tr><td>Anthropic (Claude)</td><td>The text of a transcript; for projects, the text of the project's files and notes and your questions; for screen moments, the pictures of the screen.</td><td>The Roman Urdu, Urdu and English views, suggested fixes, call notes, briefs and Ask, and reading the text on shared screens.</td></tr>
                    <tr><td>The hosting provider of this server</td><td>Everything the server stores, encrypted where described above, on its machines.</td><td>Running the server and its backups.</td></tr>
                </tbody>
            </table>
        </div>
        <p>Self-hosted engines (whisper.cpp, Parakeet) run on servers of the operator and send audio nowhere else.</p>
        <p>Other services see less: Google, when you sign in with Google, learns that you signed in; the email provider of this server carries the emails it sends you (confirmations, sign-in alerts, invitations and the like).</p>

        <h2>Who sees your recordings</h2>
        <p>Your personal workspace is yours alone. A team workspace has one library for all its members: each member sees the recordings, transcripts, screens and projects kept in it, and its owners also see its members, minutes and costs. Recordings go to the workspace your phone, computer or console is working in, so keep in your personal workspace what is only for you. Outside a workspace, only the people you share a recording with see it: a share link, or Send to a teammate. The server's operator can reach the stored data only as the hosting of it requires.</p>

        <h2>How long we keep it</h2>
        <ul>
            <li>Recordings: until you delete them or the server's retention period ends; deleted ones wait 48 hours in the undo bin, then they and the engines' copies are erased.</li>
            <li>Your account: until you delete it; 7 days later it is erased with everything only it owns.</li>
            <li>An export of your data: 24 hours.</li>
        </ul>

        <h2>Your choices</h2>
        <ul>
            <li>Download everything you keep here: Profile → Your data → Export my data.</li>
            <li>Delete your account: Profile → Your data, or the <a href="{{ route('account.delete') }}">Delete your account</a> page.</li>
            <li>See where you are signed in and sign out everywhere: Profile → Devices.</li>
        </ul>

        <h2>Recording other people</h2>
        <p>{{ \App\Support\RecordingConsent::TEXT }} {{ \App\Support\RecordingConsent::APPS }}</p>

        <p class="muted">See also the <a href="{{ route('legal.terms') }}">Terms of Service</a>.</p>
    </div>
@endsection
