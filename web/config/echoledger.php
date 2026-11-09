<?php

declare(strict_types=1);

return [

    /*
    | Dedicated 32-byte key (base64) for encrypting recordings at rest.
    | Generate with `php artisan echoledger:key`. Back it up offline: losing it makes every
    | stored recording unreadable. It is intentionally NOT derived from APP_KEY.
    */
    'recordings_key' => env('ECHOLEDGER_RECORDINGS_KEY'),

    // Defaults; the admin overrides these on the Settings page (settings table).
    'retention_days' => (int) env('ECHOLEDGER_RETENTION_DAYS', 30),

    'max_chunk_bytes' => 5 * 1024 * 1024,

    'max_recording_bytes' => (int) env('ECHOLEDGER_MAX_RECORDING_BYTES', 2 * 1024 * 1024 * 1024),

    'device_token_days' => (int) env('ECHOLEDGER_DEVICE_TOKEN_DAYS', 365),

    'pairing_code_minutes' => 10,

    'playback_url_minutes' => 30,

    // Plaintext temp files (decrypt for provider upload) live here and are deleted in `finally`.
    'tmp_dir' => storage_path('app/private/tmp'),

    // Since v1.5: the request header a proxy in front of the server names the visitor's country in (for example
    // CF-IPCountry behind Cloudflare), shown next to devices and sign-in alerts. Unset = the country is not known.
    'country_header' => env('ECHOLEDGER_COUNTRY_HEADER'),

    // The landing and downloads pages ask search engines to stay away until the public launch (since v1.5).
    'site_indexable' => (bool) env('ECHOLEDGER_SITE_INDEXABLE', false),

    'accepted_mimes' => [
        'audio/ogg', 'audio/opus', 'audio/flac', 'audio/x-flac', 'audio/wav', 'audio/x-wav', 'audio/wave',
        'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/webm', 'audio/amr', 'application/ogg',
        // What PHP's finfo reports for AMR, ADTS AAC and WebM files (the console upload checks sniffed types);
        // stored as audio/amr, audio/aac and audio/webm (RecordingIngestor::canonicalMime).
        'audio/AMR', 'audio/x-hx-aac-adts', 'video/webm',
    ],

    // Android App Links (v1.5): /.well-known/assetlinks.json lets the phone app open /s/, /p/ and /r/ links of this
    // server. The SHA-256 fingerprints of the signing certificates, comma separated: the debug builds' by default (the
    // Windows laptop's, and since 2026-11-09 this Mac's, which builds the phone app now); add the release key's when
    // there is one.
    'android_app' => [
        'package' => env('ECHOLEDGER_ANDROID_PACKAGE', 'com.echoledger.mobile'),
        'fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'ECHOLEDGER_ANDROID_FINGERPRINTS',
            '34:AC:18:19:18:77:F6:C3:12:A2:88:3E:07:C8:40:0B:85:C4:71:62:A4:0F:77:B4:FE:2D:4F:27:08:AF:F1:B6,'.
            'D2:17:D1:96:65:25:59:76:0D:C0:A9:F2:38:0A:01:69:FF:1E:07:CC:AF:20:93:FF:6D:EB:59:7A:B1:27:14:15',
        ))))),
    ],
];
