{{-- The v2.0 Download page: today's words and builds in the new design; each build is a row with its file and SHA-256. --}}
@extends('site.v2.layout')

@section('title', 'Downloads · '.\App\Support\Brand::name())

@php
    $size = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, intdiv($bytes, 1024)).' KB';
@endphp

@section('content')
    <section class="s-page-head">
        <h1 class="s-display">Get the apps</h1>
        <p class="s-lead">
            Download a build, then check it: the SHA-256 of the file you got must match the one below
            (Windows: <code class="s-code">certutil -hashfile &lt;file&gt; SHA256</code>; Mac: <code class="s-code">shasum -a 256 &lt;file&gt;</code>).
        </p>
    </section>

    <section class="s-section" aria-labelledby="get-started">
        <h2 id="get-started" class="s-title">How to get started</h2>
        <ol class="s-steps">
            <li><strong>Download and install</strong> the app for your phone or computer below.</li>
            <li><strong>Sign in</strong> with your email and password or with Google. No account yet? Choose Create account when this server offers it, or ask whoever runs it to invite you.</li>
            <li><strong>Run the Ready check</strong> the app opens with, fix anything it shows with one tap, then record a call or a voice note. Your recordings are private to you until you share them.</li>
        </ol>
    </section>

    <section class="s-section" aria-labelledby="builds">
        <h2 id="builds" class="s-title">Builds</h2>
        @if ($latest->isEmpty())
            <p class="s-text">No builds are published yet.</p>
        @else
            <ul class="s-builds">
                @foreach ($latest as $platform => $release)
                    <li class="s-build">
                        <div class="s-build-main">
                            <h3 class="s-build-name">{{ \App\Models\AppRelease::LABELS[$platform] }}</h3>
                            <p class="s-meta">Version {{ $release->version }} · {{ $size($release->bytes) }} · {{ $release->published_at->format('j M Y') }}</p>
                            @if ($release->notes)
                                <p class="s-text">{{ $release->notes }}</p>
                            @endif
                            <p class="s-meta">SHA-256 <span class="s-code s-hash">{{ $release->sha256 }}</span></p>
                        </div>
                        <a class="s-btn s-btn-primary" href="{{ route('downloads.file', ['name' => $release->file_name]) }}" download>Download</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($latest->isNotEmpty())
        <section class="s-section" aria-labelledby="installing">
            <h2 id="installing" class="s-title">Installing</h2>
            <ul class="s-bullets">
                <li><strong>Android:</strong> open the APK and allow your browser to install apps when Android asks. Play Protect may warn about an unknown developer: choose More details → Install anyway. The app then updates itself from this page.</li>
                <li><strong>Windows:</strong> run the installer. Until the app is code-signed, SmartScreen may say Windows protected your PC: choose More info → Run anyway.</li>
                <li><strong>Mac:</strong> open the disk image and drag @brand to Applications. Until the app is notarized, open it the first time with Control-click → Open.</li>
            </ul>
        </section>
    @endif

    <section class="s-section" aria-labelledby="where">
        <h2 id="where" class="s-title">What works where</h2>
        <ul class="s-bullets">
            <li><strong>Mac</strong> (Apple Silicon or Intel), macOS 14.4 or newer: voice notes, imports and both sides of calls. The app does not open on older versions.</li>
            <li><strong>Windows 11:</strong> both sides of calls, hearing only the call's app.</li>
            <li><strong>Windows 10:</strong> both sides of calls, but it records all sound playing on the computer.</li>
            <li><strong>Android 14 or newer</strong> with the capture helper: both sides of app calls.</li>
            <li><strong>Android 12 and 13</strong> with the capture helper: only your voice in app calls.</li>
            <li><strong>Android 10 and 11, or no helper:</strong> voice notes and imports, no calls: during a call Android gives other apps no sound.</li>
            <li><strong>Google Play version:</strong> voice notes and imports, no calls.</li>
        </ul>
        <p class="s-note">Tested so far on phones: Samsung Galaxy S25 Ultra. In the app, Help &amp; diagnostics → Device check says what your phone can do.</p>
    </section>

    @if ($older->isNotEmpty())
        <section class="s-section">
            <details class="s-details">
                <summary>Older versions</summary>
                <ul class="s-bullets">
                    @foreach ($older as $release)
                        <li>
                            {{ \App\Models\AppRelease::LABELS[$release->platform] }} {{ $release->version }}
                            · <a class="s-link" href="{{ route('downloads.file', ['name' => $release->file_name]) }}" download>{{ $release->file_name }}</a>
                            · {{ $size($release->bytes) }}
                            <br><span class="s-code s-hash">{{ $release->sha256 }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        </section>
    @endif
@endsection
