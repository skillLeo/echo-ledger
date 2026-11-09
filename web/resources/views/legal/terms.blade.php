@extends('site.layout')

@section('title', 'Terms of Service · EchoLedger')

@section('content')
    <div class="legal">
        @include('legal.draft')
        <h1>Terms of Service</h1>
        <p class="muted">Last changed {{ \Illuminate\Support\Carbon::parse('2026-11-06')->format('F j, Y') }}</p>

        <h2>Using EchoLedger</h2>
        <p>EchoLedger records calls and meetings on your phone and computer and turns them into transcripts. These terms apply to this server, its console and the EchoLedger apps when they are signed in to it. By making an account or signing in, you agree to them.</p>

        <h2>Your account</h2>
        <ul>
            <li>Keep your password and two-step codes to yourself. You are responsible for what is done with your account.</li>
            <li>Tell us at once if you think someone else used it: you can sign out everywhere under Account → Devices.</li>
            <li>You can delete your account at any time under Account → Privacy & data, or on the <a href="{{ route('account.delete') }}">Delete your account</a> page. It is erased 7 days later.</li>
        </ul>

        <h2>Recording other people</h2>
        <p>{{ \App\Support\RecordingConsent::TEXT }} You are responsible for having the right to record and for telling the people you record, as the laws where you and they are require. EchoLedger shows when it is recording; it does not ask anyone's consent for you.</p>

        <h2>Your recordings</h2>
        <ul>
            <li>What you record and upload stays yours. We use it only to run the service for you: to store it, transcribe it, make the views and notes you ask for, and show it to the people you share it with.</li>
            <li>Your recordings, transcripts and projects are private to you until you share them, also inside a team workspace: its owners and admins see your minutes and costs, never your content.</li>
            <li>Recordings are deleted after the retention period the server is set to, unless you keep them.</li>
        </ul>

        <h2>What you may not do</h2>
        <ul>
            <li>Record people where the law does not allow it, or use EchoLedger to harass, deceive or spy on anyone.</li>
            <li>Upload anything you have no right to, or anything unlawful.</li>
            <li>Try to reach other people's data, break the service's security, or overload it.</li>
        </ul>

        <h2>Transcripts are machine-made</h2>
        <p>Transcripts, translations, views and notes are made by speech and language engines. They can be wrong, especially for unclear audio. Check what matters before you rely on it.</p>

        <h2>The service</h2>
        <p>We keep the service running as well as we can, but it is provided as it is, without a promise that it is always available or free of mistakes. We may change or stop parts of it; when that affects your data, we tell you first and give you time to export it.</p>

        <h2>Ending</h2>
        <p>You can stop using EchoLedger and delete your account at any time. We may close an account that breaks these terms, after telling its owner where we can.</p>

        <h2>Changes</h2>
        <p>When these terms change, the date above changes and signed-in people are told in the console.</p>

        <p class="muted">See also the <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>
    </div>
@endsection
