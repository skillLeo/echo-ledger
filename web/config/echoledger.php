<?php

declare(strict_types=1);

return [

    /*
    | The product's name as people read it (since v1.9, L0): the website, the console and the admin panel, emails,
    | notifications and the messages the apps and the connector show. A rename is this one value (read through
    | App\Support\Brand); the desktop and phone apps have their own brand files. Technical names never follow it: this
    | `echoledger` namespace, the echoledger:// links, cookies, storage paths, webhook headers and release file names.
    */
    'brand' => [
        'name' => 'EchoLedger',
    ],

    /*
    | Dedicated 32-byte key (base64) for encrypting recordings at rest.
    | Generate with `php artisan echoledger:key`. Back it up offline: losing it makes every
    | stored recording unreadable. It is intentionally NOT derived from APP_KEY.
    */
    'recordings_key' => env('ECHOLEDGER_RECORDINGS_KEY'),

    // Defaults; the admin overrides these on the Settings page (settings table).
    'retention_days' => (int) env('ECHOLEDGER_RETENTION_DAYS', 30),

    'max_chunk_bytes' => 5 * 1024 * 1024,

    // Pictures (screens, receipts) decoded at a time on one server, about 200 MB each at most (one at a time per person),
    // and how long one waits for a free slot before the answer is 503 server_busy (App\Services\Screens\ScreenStore::decoding).
    'picture_decodes' => [
        'slots' => (int) env('ECHOLEDGER_PICTURE_DECODE_SLOTS', 2),
        'wait_seconds' => (float) env('ECHOLEDGER_PICTURE_DECODE_WAIT', 4),
    ],

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
    // server, and (since 2.0.0-l) the phone add passkeys for it. The SHA-256 fingerprints of the signing certificates,
    // comma separated, from ECHOLEDGER_ANDROID_FINGERPRINTS: the release key's on a server, the debug builds' in a local
    // .env (see .env.example).
    'android_app' => [
        'package' => env('ECHOLEDGER_ANDROID_PACKAGE', 'com.echoledger.mobile'),
        'fingerprints' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'ECHOLEDGER_ANDROID_FINGERPRINTS',
            // Since the security review of 2.0.0-l empty by default: the debug keys go in a local .env only, and with
            // none set phone passkeys are off (passkeys_available false) and App Links list no key.
            '',
        ))))),
    ],
];
