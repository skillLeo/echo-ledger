<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
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

    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('progress', ['done' => 2, 'total' => 5, 'eta_seconds' => null]);
    $item = collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $session->id);
    expect($item['transcript']['final_progress'])->toBe(['done' => 2, 'total' => 5, 'eta_seconds' => null]);

    $transcript->update(['status' => TranscriptStatus::Completed]);
    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('progress', null);

    $transcript->update(['status' => TranscriptStatus::Processing, 'meta' => ['progress' => ['chunks_done' => 0, 'chunks_total' => 1]]]);
    // Since v1.5 (Phase 1C): a run in one piece has progress too (one part), without a measure no time left.
    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('progress', ['done' => 0, 'total' => 1, 'eta_seconds' => null]);
});

it('gives every run progress with the time left from the engine\'s measured speed on this server', function () {
    // Two finished Groq runs: 60 s of audio in 20 s and 120 s in 40 s, so Groq does 3 s of audio a second here.
    foreach ([[60000, 20], [120000, 40]] as [$ms, $took]) {
        $done = $this->makeReadySession($this->device, Fixtures::audio(4000), ['duration_ms' => $ms, 'status' => SessionStatus::Completed]);
        $done->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed,
            'submitted_at' => now()->subMinutes(30), 'completed_at' => now()->subMinutes(30)->addSeconds($took)]);
    }
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['duration_ms' => 300000]);
    $parts = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Processing,
        'submitted_at' => now(), 'meta' => ['progress' => ['chunks_done' => 2, 'chunks_total' => 5]]]);
    // 3 of 5 parts of 300 s left = 180 s of audio at 3 s a second.
    $this->getJson("/api/transcripts/{$parts->id}")->assertOk()->assertJsonPath('progress', ['done' => 2, 'total' => 5, 'eta_seconds' => 60]);

    // In one piece: 300 s at 3 s a second is 100 s, of which 40 s have gone.
    $this->travelTo(now());
    $parts->update(['meta' => [], 'submitted_at' => now()->subSeconds(40), 'status' => TranscriptStatus::Submitted]);
    $this->getJson("/api/transcripts/{$parts->id}")->assertOk()->assertJsonPath('progress', ['done' => 0, 'total' => 1, 'eta_seconds' => 60]);

    // An engine that never finished a run here has no measure yet.
    $parts->update(['provider' => 'assemblyai']);
    $this->getJson("/api/transcripts/{$parts->id}")->assertOk()->assertJsonPath('progress.eta_seconds', null);

    $parts->update(['status' => TranscriptStatus::Failed]);
    $this->getJson("/api/transcripts/{$parts->id}")->assertOk()->assertJsonPath('progress', null);
});
