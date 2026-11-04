@extends('site.layout')

@section('title', 'Downloads · EchoLedger')

@php
    $size = fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : max(1, intdiv($bytes, 1024)).' KB';
@endphp

@section('content')
    <h1>Get the apps</h1>
    <p class="muted" style="max-width: 640px;">
        Download a build, then check it: the SHA-256 of the file you got must match the one below
        (Windows: <code>certutil -hashfile &lt;file&gt; SHA256</code>; Mac: <code>shasum -a 256 &lt;file&gt;</code>).
    </p>

    @if ($latest->isEmpty())
        <div class="card"><p class="muted" style="margin: 0;">No builds are published yet.</p></div>
    @else
        <div class="grid">
            @foreach ($latest as $platform => $release)
                <section class="card" aria-label="{{ \App\Models\AppRelease::LABELS[$platform] }}">
                    <h2>{{ \App\Models\AppRelease::LABELS[$platform] }}</h2>
                    <p style="margin: 0 0 4px;">Version {{ $release->version }} · {{ $size($release->bytes) }}</p>
                    <p class="muted" style="margin: 0 0 12px; font-size: 14px;">{{ $release->published_at->format('j M Y') }}</p>
                    @if ($release->notes)
                        <p style="margin: 0 0 12px; font-size: 15px;">{{ $release->notes }}</p>
                    @endif
                    <p><a class="button" href="{{ route('downloads.file', ['name' => $release->file_name]) }}" download>Download</a></p>
                    <p class="muted" style="margin: 0; font-size: 13px;">SHA-256</p>
                    <p class="mono" style="margin: 0;">{{ $release->sha256 }}</p>
                </section>
            @endforeach
        </div>

        <section class="card" style="margin-top: 24px;">
            <h2>Installing</h2>
            <ul class="muted" style="margin: 0; padding-left: 20px;">
                <li><strong>Android:</strong> open the APK and allow your browser to install apps when Android asks. Play Protect may warn about an unknown developer: choose More details → Install anyway. The app then updates itself from this page.</li>
                <li><strong>Windows:</strong> run the installer. Until the app is code-signed, SmartScreen may say Windows protected your PC: choose More info → Run anyway.</li>
                <li><strong>Mac:</strong> open the disk image and drag EchoLedger to Applications. Until the app is notarized, open it the first time with Control-click → Open.</li>
            </ul>
        </section>
    @endif

    @if ($older->isNotEmpty())
        <section style="margin-top: 24px;">
            <details class="card">
                <summary>Older versions</summary>
                <ul style="margin: 12px 0 0; padding-left: 20px;">
                    @foreach ($older as $release)
                        <li style="margin-bottom: 8px;">
                            {{ \App\Models\AppRelease::LABELS[$release->platform] }} {{ $release->version }}
                            · <a href="{{ route('downloads.file', ['name' => $release->file_name]) }}" download>{{ $release->file_name }}</a>
                            · {{ $size($release->bytes) }}
                            <br><span class="mono muted">{{ $release->sha256 }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        </section>
    @endif
@endsection
