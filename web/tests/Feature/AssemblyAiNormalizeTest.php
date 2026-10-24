<?php

declare(strict_types=1);

use App\Stt\Drivers\AssemblyAiDriver;

/** Fixture-driven mapping of a multichannel GET /v2/transcript/{id} body to the shared segment shape. */
it('maps multichannel utterances to Me (left) and Client (right), sorted by time', function () {
    $fixture = [
        'audio_channels' => 2,
        'language_code' => 'en',
        'speech_model_used' => 'universal-3-5-pro',
        'utterances' => [
            ['channel' => '2', 'start' => 1500, 'end' => 3200, 'text' => 'Thanks for calling Acme.', 'words' => [
                ['text' => 'Thanks', 'start' => 1500, 'end' => 1800, 'confidence' => 0.98],
            ]],
            ['channel' => '1', 'start' => 200, 'end' => 1300, 'text' => 'Hi, this is Farah.', 'words' => [
                ['text' => 'Hi,', 'start' => 200, 'end' => 400, 'confidence' => 0.97],
            ]],
        ],
    ];

    $result = app(AssemblyAiDriver::class)->normalize($fixture);

    expect($result->segments)->toHaveCount(2);
    expect($result->segments[0]->speaker)->toBe('Me');      // channel 1 first: it starts earlier
    expect($result->segments[0]->channel)->toBe(1);
    expect($result->segments[1]->speaker)->toBe('Client');
    expect($result->segments[1]->text)->toBe('Thanks for calling Acme.');
    expect($result->text)->toBe("Me: Hi, this is Farah.\nClient: Thanks for calling Acme.");
    expect($result->language)->toBe('en');
});

it('drops acoustic bleed when the far party is echoed into the mic channel', function () {
    $fixture = [
        'audio_channels' => 2,
        'utterances' => [
            ['channel' => '2', 'start' => 1000, 'end' => 3000, 'text' => 'The invoice will be paid on Friday morning.', 'words' => []],
            ['channel' => '1', 'start' => 1100, 'end' => 3100, 'text' => 'The invoice will be paid on Friday morning.', 'words' => []],
        ],
    ];

    $result = app(AssemblyAiDriver::class)->normalize($fixture);

    expect($result->segments)->toHaveCount(1);
    expect($result->segments[0]->speaker)->toBe('Client');
});

it('labels a mono transcript with a single speaker', function () {
    $fixture = [
        'audio_channels' => 1,
        'text' => 'A short voice note.',
        'words' => [
            ['text' => 'A', 'start' => 0, 'end' => 100],
            ['text' => 'short', 'start' => 120, 'end' => 400],
            ['text' => 'voice', 'start' => 420, 'end' => 700],
            ['text' => 'note.', 'start' => 720, 'end' => 1000],
        ],
    ];

    $result = app(AssemblyAiDriver::class)->normalize($fixture);

    expect($result->segments)->toHaveCount(1);
    expect($result->segments[0]->speaker)->toBe('Speaker');
    expect($result->text)->toBe('A short voice note.');
});
