@extends('site.layout')

@section('title', 'Terms of Service · EchoLedger')

@section('content')
    <div class="legal">
        @include('legal.draft')
        <h1>Terms of Service</h1>
        <p class="muted">Last changed {{ \Illuminate\Support\Carbon::parse('2026-11-09')->format('F j, Y') }}</p>

        <h2>What EchoLedger is</h2>
        <p>EchoLedger keeps a complete record of the conversations that matter in your work: meetings with clients, with your manager or your team, interviews, consultations and voice notes. It records them on your phone or computer, with both sides of the conversation, and turns each one into a written record you can search, share and rely on: a transcript of who said what, readable versions in Roman Urdu, Urdu and English, notes and summaries, next to the recording itself.</p>
        <p>EchoLedger is provided by SkillLeo SMC Private Limited, Pakistan ("we"). These terms apply to the EchoLedger website, its console and the EchoLedger apps for phone and computer. By making an account or signing in, you agree to them.</p>

        <h2>Your account</h2>
        <ul>
            <li>Keep your password, sign-in codes and two-step codes to yourself. You are responsible for what is done with your account.</li>
            <li>Tell us at once if you think someone else used it; you can sign out every device under Account → Devices.</li>
            <li>You can delete your account at any time under Account → Privacy & data, or on the <a href="{{ route('account.delete') }}">Delete your account</a> page. It is erased 7 days later, with everything only it owns.</li>
        </ul>

        <h2>Recording other people</h2>
        <p>{{ \App\Support\RecordingConsent::TEXT }} You are responsible for having the right to record the people in your meetings and for telling them, as the laws where you and they are require. EchoLedger always shows when it is recording; it does not ask for anyone's consent on your behalf.</p>

        <h2>Your recordings are yours</h2>
        <ul>
            <li>What you record and upload stays yours. We use it only to provide the service to you: to store it, transcribe it, make the readable versions, notes and summaries you ask for, and show it to the people you choose to share it with.</li>
            <li>Your recordings, transcripts and projects are private to you until you share them, also inside a team workspace: its owners and admins see how many minutes you used, never what you recorded.</li>
            <li>We never sell your recordings or transcripts, never use them for advertising, and never show them to anyone you did not share them with. How they are protected is set out in the <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</li>
            <li>Recordings are kept for the period your plan allows, unless you keep them or delete them sooner.</li>
        </ul>

        <h2>Transcripts, translations and notes</h2>
        <p>Transcripts, translations, readable versions, notes and summaries are produced automatically by speech-recognition and language engines. They are usually accurate but can contain mistakes, especially with unclear audio, strong background noise or several people speaking at once. EchoLedger marks words it is unsure of; check what matters, such as names, figures and decisions, against the recording before you rely on it.</p>

        <h2>Plans and payment</h2>
        <ul>
            <li><strong>Free</strong> costs nothing. Transcripts are made with your own free transcription key, which you add in the app.</li>
            <li><strong>Pro</strong> and <strong>Ultra</strong> are paid monthly at the prices shown in the app. You request a plan in the app and pay by bank transfer as the app explains; the plan is switched on once we have confirmed your payment, and runs until the date shown under Plan &amp; usage.</li>
            <li>When a paid month ends without a new payment, your account returns to Free. Nothing you recorded is lost; Free's limits apply from then on.</li>
            <li>Each plan has limits (minutes of transcription a month, devices, storage), shown under Plan &amp; usage. If you reach one, the app says so and what you can do.</li>
            <li>For anything about a payment, write to <a href="mailto:hassam.dev.571@gmail.com">hassam.dev.571@gmail.com</a>.</li>
        </ul>

        <h2>Fair use</h2>
        <ul>
            <li>Do not record people where the law does not allow it, and do not use EchoLedger to harass, deceive or spy on anyone.</li>
            <li>Do not upload anything you have no right to, or anything unlawful.</li>
            <li>Do not try to reach other people's data, break the service's security, or overload it.</li>
        </ul>

        <h2>The service</h2>
        <p>We work to keep EchoLedger available, secure and accurate, but it is provided as it is, without a promise that it is always available or free of mistakes. We may improve, change or stop parts of it; when a change affects your data, we tell you first and give you time to export it. To the extent the law allows, we are not liable for indirect losses arising from the use of transcripts or from the service being unavailable.</p>

        <h2>Ending</h2>
        <p>You can stop using EchoLedger and delete your account at any time. We may suspend or close an account that breaks these terms, after telling its owner where we can.</p>

        <h2>Changes and contact</h2>
        <p>When these terms change, the date above changes and signed-in people are told in the app. These terms are governed by the laws of Pakistan. Questions: <a href="mailto:hassam.dev.571@gmail.com">hassam.dev.571@gmail.com</a>.</p>

        <p class="muted">See also the <a href="{{ route('legal.privacy') }}">Privacy Policy</a>.</p>
    </div>
@endsection
