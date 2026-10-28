<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/* Progress (v1.2): a long recording transcribed in parts says how many parts are done, while it is being made. */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

it('reports the parts done while the transcript is being made, and nothing once it is done', function () {
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000));
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Processing,
        'meta' => ['progress' => ['chunks_done' => 2, 'chunks_total' => 5]]]);

    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('progress', ['done' => 2, 'total' => 5]);
    $item = collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $session->id);
    expect($item['transcript']['final_progress'])->toBe(['done' => 2, 'total' => 5]);

    $transcript->update(['status' => TranscriptStatus::Completed]);
    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('progress', null);

    $transcript->update(['status' => TranscriptStatus::Processing, 'meta' => ['progress' => ['chunks_done' => 0, 'chunks_total' => 1]]]);
    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('progress', null); // one part: nothing to count
});
