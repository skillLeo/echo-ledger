{{--
    A recording's source as its app's own mark (PLAN_V2_PARITY 3: "real logos, never generic glyphs for known sources"),
    with a small badge for the device it was recorded on. The same set as the desktop app (desktop/src/components/
    SourceLogos.tsx) and the phone: simplified, flat marks on a 24-unit tile in each app's own colours. They identify the
    source only. The colours are the apps', not ours, which is why they live here, outside the token-only v2 views.

    @include('filament.v2.components.source-logo', [
        'app' => $row['app'],         // SourceApp value: whatsapp, phone_call, zoom… (null or unknown: the fallback glyph)
        'device' => $row['device'],   // 'phone' | 'computer' | 'web' | null (no badge)
        'label' => $row['source'],    // optional: names the mark for assistive tools; otherwise it is decorative
        'fallback' => $row['icon'],   // optional: the v2 icon for a source without a mark (default 'mic')
    ])
    Its size comes from CSS (.v2-source, 32 px; .v2-source--sm, 24 px).
--}}
@php
    $marks = [
        'whatsapp' => '<circle cx="12" cy="12" r="12" fill="#25D366"/><path d="M12 4.6a7.4 7.4 0 0 0-6.4 11.1L4.6 19.4l3.8-1a7.4 7.4 0 1 0 3.6-13.8z" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round" /> <path d="M9.3 7.6c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.1.3 0 .5.4.7.9 1.3 1.5 1.8.5.4 1 .7 1.6 1 .2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.6.8c.2.1.3.3.3.5 0 .5-.2 1-.6 1.3-.5.4-1.1.6-1.7.5-1.1-.2-2.2-.7-3.1-1.4-1-.8-1.9-1.8-2.5-2.9-.4-.7-.5-1.5-.3-2.2.2-.4.4-.8.8-1.1.2-.2.4-.2.6-.2z" fill="#FFFFFF" />',
        'whatsapp_business' => '<circle cx="12" cy="12" r="12" fill="#25D366"/><path d="M12 4.6a7.4 7.4 0 0 0-6.4 11.1L4.6 19.4l3.8-1a7.4 7.4 0 1 0 3.6-13.8z" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round" /> <path d="M10 8.2h2.4c1.2 0 2 .6 2 1.6 0 .6-.3 1-.8 1.3.7.2 1.1.7 1.1 1.4 0 1.1-.8 1.8-2.2 1.8H10zm1.3 1.1v1.5h1c.5 0 .8-.3.8-.8s-.3-.7-.8-.7zm0 2.5v1.6h1.2c.6 0 .9-.3.9-.8s-.3-.8-.9-.8z" fill="#FFFFFF" />',
        'whatsapp_web' => '<circle cx="12" cy="12" r="12" fill="#25D366"/><path d="M12 4.6a7.4 7.4 0 0 0-6.4 11.1L4.6 19.4l3.8-1a7.4 7.4 0 1 0 3.6-13.8z" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linejoin="round" /> <path d="M9.3 7.6c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.6l-.5.6c-.1.1-.1.3 0 .5.4.7.9 1.3 1.5 1.8.5.4 1 .7 1.6 1 .2.1.4 0 .5-.1l.6-.7c.2-.2.4-.2.6-.1l1.6.8c.2.1.3.3.3.5 0 .5-.2 1-.6 1.3-.5.4-1.1.6-1.7.5-1.1-.2-2.2-.7-3.1-1.4-1-.8-1.9-1.8-2.5-2.9-.4-.7-.5-1.5-.3-2.2.2-.4.4-.8.8-1.1.2-.2.4-.2.6-.2z" fill="#FFFFFF" />',
        'phone_call' => '<rect width="24" height="24" rx="6" fill="#34C759"/><path d="M8.6 5.5c.3 0 .6.1.7.5l1 2.4c.1.3 0 .6-.2.8l-.8.9c-.1.2-.1.4 0 .6.6 1 1.3 1.8 2.1 2.6.7.6 1.5 1.1 2.3 1.5.2.1.5.1.7-.1l.8-1c.2-.3.6-.3.9-.2l2.3 1.1c.3.1.5.4.5.7 0 .7-.3 1.4-.9 1.9-.7.6-1.6.9-2.5.7-1.7-.3-3.2-1-4.5-2.1-1.5-1.2-2.7-2.6-3.6-4.2-.5-1-.7-2.2-.4-3.2.2-.6.6-1.1 1.1-1.5.2-.2.4-.2.5-.2z" fill="#FFFFFF" />',
        'google_meet' => '<rect width="24" height="24" rx="6" fill="#FFFFFF"/><rect x="0.5" y="0.5" width="23" height="23" rx="5.5" fill="none" stroke="#DADCE0" /> <rect x="4" y="7" width="11" height="10" rx="1.5" fill="#00AC47" /> <path d="M4 10V8.5C4 7.7 4.7 7 5.5 7H8L4 10z" fill="#EA4335" /> <path d="M4 10h3v7H5.5C4.7 17 4 16.3 4 15.5z" fill="#4285F4" /> <path d="M7 14h8v1.5c0 .8-.7 1.5-1.5 1.5H7z" fill="#0066DA" /> <path d="M15 10.5 20 7v10l-5-3.5z" fill="#00832D" />',
        'zoom' => '<rect width="24" height="24" rx="6" fill="#0B5CFF"/><rect x="4.5" y="8" width="10" height="8" rx="2" fill="#FFFFFF" /> <path d="M15.5 11 19.5 8.4v7.2l-4-2.6z" fill="#FFFFFF" />',
        'microsoft_teams' => '<rect width="24" height="24" rx="6" fill="#5059C9"/><circle cx="17.2" cy="7.6" r="2.1" fill="#7B83EB" /> <path d="M14.6 10.6h5c.5 0 .9.4.9.9v3.9a2.7 2.7 0 0 1-5.4.2z" fill="#7B83EB" /> <rect x="3.5" y="6.5" width="11" height="11" rx="1.5" fill="#4B53BC" /> <path d="M6.2 9.2h5.6v1.5H9.8v5h-1.6v-5h-2z" fill="#FFFFFF" />',
        'slack' => '<rect width="24" height="24" rx="6" fill="#FFFFFF"/><rect x="0.5" y="0.5" width="23" height="23" rx="5.5" fill="none" stroke="#DADCE0" /> <rect x="8.4" y="4.5" width="2.6" height="9" rx="1.3" fill="#36C5F0" /> <rect x="10.5" y="8.4" width="9" height="2.6" rx="1.3" fill="#2EB67D" /> <rect x="13" y="10.5" width="2.6" height="9" rx="1.3" fill="#ECB22E" /> <rect x="4.5" y="13" width="9" height="2.6" rx="1.3" fill="#E01E5A" />',
        'telegram' => '<circle cx="12" cy="12" r="12" fill="#26A5E4"/><path d="m5.4 11.6 11.7-4.5c.5-.2 1 .1.8.9l-2 9.4c-.1.7-.5.8-1.1.5l-3-2.2-1.5 1.4c-.2.2-.3.3-.6.3l.2-3.1 5.6-5.1c.2-.2 0-.3-.4-.1l-6.9 4.4-3-.9c-.6-.2-.6-.6.2-1z" fill="#FFFFFF" />',
        'voice_note' => '<circle cx="12" cy="12" r="12" fill="#5F6B73"/><rect x="9.5" y="5" width="5" height="9" rx="2.5" fill="#FFFFFF" /> <path d="M7.2 11.5a4.8 4.8 0 0 0 9.6 0M12 16.4v2.6" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linecap="round" />',
    ];
    $app = $app ?? null;
    $mark = is_string($app) && array_key_exists($app, $marks) ? $marks[$app] : null;
    // A phone recording's badge is the device (a handset would read as a phone call, next to the Phone call mark).
    $deviceIcon = match ($device ?? null) {
        'phone' => 'smartphone',
        'computer', 'desktop', 'laptop' => 'device',
        'web' => 'globe',
        default => null,
    };
@endphp
<span @class(['v2-source', 'v2-source--sm' => ($size ?? null) === 'sm']) @if (! empty($label)) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    @if ($app === 'voice_note')
        {{-- EchoLedger's own source: the app's calm accent with the voice glyph (D12), not a grey mic. --}}
        <span class="v2-source-mark v2-source--voice">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 10v3" /><path d="M6 6v11" /><path d="M10 3v18" /><path d="M14 8v7" /><path d="M18 5v13" /><path d="M22 10v3" /></svg>
        </span>
    @elseif ($mark)
        <svg class="v2-source-mark" viewBox="0 0 24 24" focusable="false" aria-hidden="true">{!! $mark !!}</svg>
    @else
        <span class="v2-source-fallback"><x-el.v2.icon :name="$fallback ?? 'mic'" /></span>
    @endif
    @if ($deviceIcon)
        <span class="v2-source-device">
            @if ($deviceIcon === 'smartphone')
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="7" y="2.5" width="10" height="19" rx="2.2" /><path d="M11 18.5h2" /></svg>
            @else
                <x-el.v2.icon :name="$deviceIcon" />
            @endif
        </span>
    @endif
</span>
