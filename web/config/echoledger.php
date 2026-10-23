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

    'accepted_mimes' => [
        'audio/ogg', 'audio/opus', 'audio/flac', 'audio/x-flac', 'audio/wav', 'audio/x-wav', 'audio/wave',
        'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/webm', 'audio/amr', 'application/ogg',
    ],
];
