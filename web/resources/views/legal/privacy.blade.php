@extends('site.layout')

@section('title', 'Privacy Policy · EchoLedger')

@section('content')
    <div class="legal">
        @include('legal.draft')
        <h1>Privacy Policy</h1>
        <p class="muted">Last changed {{ \Illuminate\Support\Carbon::parse('2026-11-09')->format('F j, Y') }}</p>

        <p>EchoLedger keeps a complete written record of your meetings, interviews and voice notes. Those conversations are often confidential, so protecting them is the most important thing we do. This policy explains, in plain words, what we keep, how it is protected, who processes it and what you control. EchoLedger is provided by SkillLeo SMC Private Limited, Pakistan.</p>

        <h2>Our promises</h2>
        <ul>
            <li><strong>Your recordings are yours.</strong> We use them only to provide the service to you.</li>
            <li><strong>We never sell your data</strong> and never use it for advertising.</li>
            <li><strong>Nobody sees your recordings unless you share them</strong>: not other people in your workspace, not its owners or admins, not our staff.</li>
            <li><strong>They are encrypted at every step</strong>: on your phone, on the way to our server, and in storage.</li>
            <li><strong>You can export or delete everything</strong> at any time.</li>
        </ul>

        <h2>How your recordings are protected</h2>
        <ul>
            <li><strong>On your phone:</strong> a recording is stored encrypted until it has been uploaded.</li>
            <li><strong>On the way:</strong> the apps and the website talk to our server only over encrypted connections (TLS), and the apps check that it really is our server (certificate pinning), so nobody in between can read or change what is sent.</li>
            <li><strong>In storage:</strong> the audio is encrypted on our server with a key kept separately from the server's own login details; transcripts, notes and project files are kept on the same server behind your account.</li>
            <li><strong>Access:</strong> only you, and the people you choose to share a recording with, can open it. The admin panel of EchoLedger shows how much each account uses, never what it recorded. If you ask for help with a recording, you can let support look for a limited time (Account → Privacy & data → Support access); every look is logged and shown to you.</li>
            <li><strong>Your sign-in:</strong> passwords are stored only as a one-way hash, two-step sign-in is available for every account, and each phone or computer has its own sign-in that you can end at any time.</li>
        </ul>

        <h2>What we keep</h2>
        <ul>
            <li><strong>Your account:</strong> your name, email, password (as a one-way hash), two-step and passkey details, a linked Google account's id, and your settings.</li>
            <li><strong>Your recordings:</strong> the audio, encrypted on the server with a key that is not the server's own login key; transcripts, views, notes, screens and project files, stored with them.</li>
            <li><strong>Devices and sign-ins:</strong> the phones and computers signed in to your account, the browsers you sign in from, their addresses, and an audit log of sign-ins, shares, deletions and settings changes, kept for a year.</li>
            <li><strong>Usage:</strong> how many minutes each engine transcribed and what it cost, per workspace.</li>
        </ul>

        <h2>Transcription and translation</h2>
        <p>A transcript is made by sending the recording's audio to a speech-recognition engine; the readable versions in Roman Urdu, Urdu and English, the notes and the summaries are made by sending the transcript's text to a language engine. Live Translate for games and videos sends the sound your phone plays directly from your phone to the translation engine, with a key that works for a few minutes. These engines are the only ones that receive your recordings, and only for the task you asked for.</p>

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
                    @if (config('filesystems.disks.recordings.driver') === 's3-compatible')
                        <tr><td>The storage company this server uses</td><td>The encrypted files of recordings, screens, projects, exports and uploads under way, with their sizes and dates; never a key that opens them.</td><td>Keeping the files and their backups.</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
        <p>Self-hosted engines (whisper.cpp, Parakeet) run on servers of the operator and send audio nowhere else.</p>
        <p>Other services see less: Google, when you sign in with Google, learns that you signed in; the email provider of this server carries the emails it sends you (confirmations, sign-in alerts, invitations and the like).</p>

        <h2>Who sees your recordings</h2>
        <p>Your recordings, transcripts, screens and projects are yours alone, in your personal workspace and in a team workspace too: nobody else in the workspace sees them, its owners and admins included, until you share them with a share link or Send to a teammate. A team's owners and admins see who is in it and each person's minutes and costs, never what they recorded. If you leave a team or are removed from it, what you recorded there stays yours and seen by nobody else. The server's operator sees each workspace's members and usage, and can reach the stored data only as the hosting of it requires.</p>

        <h2>How long we keep it</h2>
        <ul>
            <li>Recordings: until you delete them or the server's retention period ends; deleted ones wait 48 hours in the undo bin, then they and the engines' copies are erased.</li>
            <li>Your account: until you delete it; 7 days later it is erased with everything only it owns.</li>
            <li>An export of your data: 24 hours.</li>
        </ul>

        <h2>Your choices</h2>
        <ul>
            <li>Download everything you keep here: Account → Privacy & data → Export my data.</li>
            <li>Delete your account: Account → Privacy & data, or the <a href="{{ route('account.delete') }}">Delete your account</a> page.</li>
            <li>See where you are signed in and sign out everywhere: Account → Devices.</li>
        </ul>

        <h2>Recording other people</h2>
        <p>{{ \App\Support\RecordingConsent::TEXT }} {{ \App\Support\RecordingConsent::APPS }}</p>

        <h2>Questions</h2>
        <p>Write to <a href="mailto:hassam.dev.571@gmail.com">hassam.dev.571@gmail.com</a> about anything in this policy or about your data. When this policy changes, the date above changes and signed-in people are told in the app.</p>

        <p class="muted">See also the <a href="{{ route('legal.terms') }}">Terms of Service</a>.</p>
    </div>
@endsection
