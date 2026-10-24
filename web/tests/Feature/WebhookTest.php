<?php

declare(strict_types=1);

use App\Enums\RecordingRole;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Jobs\ProcessSttWebhookJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\WebhookEvent;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\TranscriptStore;
use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\SttManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('rejects an ElevenLabs webhook with no signature', function () {
    app(CredentialVault::class)->put('elevenlabs', 'webhook_secret', 'whsec_x');
    $this->postJson('/api/webhooks/stt/elevenlabs', ['type' => 'x'])->assertStatus(401);
});

it('rejects an AssemblyAI webhook with a bad token', function () {
    app(CredentialVault::class)->put('assemblyai', 'webhook_secret', 'whsec_x');
    $this->postJson('/api/webhooks/stt/assemblyai?rid=rec_1', ['transcript_id' => 'job_1', 'status' => 'completed'], [
        'X-EchoLedger-Webhook-Token' => 'wrong',
    ])->assertStatus(401);
});

it('returns 404 for an unknown provider', function () {
    $this->postJson('/api/webhooks/stt/nope', [])->assertStatus(404);
});

it('processes a valid AssemblyAI webhook immediately and is idempotent on replay', function () {
    Queue::fake();
    app(CredentialVault::class)->put('assemblyai', 'webhook_secret', 'whsec_secret');
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key');
    Http::fake(function ($request) {
        // GET returns the finished transcript; the DELETE that follows storing it returns an empty 200.
        if ($request->method() === 'GET') {
            return Http::response([
                'id' => 'job_1', 'status' => 'completed', 'audio_channels' => 2, 'language_code' => 'en',
                'utterances' => [
                    ['channel' => '1', 'start' => 0, 'end' => 900, 'text' => 'Hello there.', 'words' => []],
                    ['channel' => '2', 'start' => 1000, 'end' => 2000, 'text' => 'Hi, thanks for calling.', 'words' => []],
                ],
            ]);
        }

        return Http::response([], 200);
    });

    $session = CaptureSession::factory()->create();
    $transcript = $session->transcripts()->create([
        'kind' => TranscriptKind::Final,
        'provider' => 'assemblyai',
        'status' => TranscriptStatus::Submitted,
        'provider_job_id' => 'job_1',
    ]);

    $token = AssemblyAiDriver::webhookToken('whsec_secret', $transcript->id);
    $post = fn () => $this->postJson(
        '/api/webhooks/stt/assemblyai?rid='.$transcript->id,
        ['transcript_id' => 'job_1', 'status' => 'completed'],
        ['X-EchoLedger-Webhook-Token' => $token],
    );

    $post()->assertOk()->assertJsonPath('received', true);

    // Stored during the webhook request itself: no waiting for the scheduled queue run.
    $transcript->refresh();
    expect($transcript->status)->toBe(TranscriptStatus::Completed);
    expect($transcript->text)->toBe("Me: Hello there.\nClient: Hi, thanks for calling.");
    expect($transcript->provider_deleted_at)->not->toBeNull();
    expect(WebhookEvent::first()->status)->toBe('processed');
    Queue::assertNothingPushed();

    // A replayed delivery is recorded once and does no work twice.
    $post()->assertOk();
    expect(WebhookEvent::count())->toBe(1);
    Http::assertSentCount(2); // one GET, one DELETE
});

it('falls back to the queue when the provider is temporarily unreachable', function () {
    Queue::fake();
    app(CredentialVault::class)->put('assemblyai', 'webhook_secret', 'whsec_secret');
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key');
    Http::fake(['*' => Http::response(['error' => 'upstream unavailable'], 503)]);

    $session = CaptureSession::factory()->create();
    $transcript = $session->transcripts()->create([
        'kind' => TranscriptKind::Final,
        'provider' => 'assemblyai',
        'status' => TranscriptStatus::Submitted,
        'provider_job_id' => 'job_1',
    ]);

    $this->postJson(
        '/api/webhooks/stt/assemblyai?rid='.$transcript->id,
        ['transcript_id' => 'job_1', 'status' => 'completed'],
        ['X-EchoLedger-Webhook-Token' => AssemblyAiDriver::webhookToken('whsec_secret', $transcript->id)],
    )->assertOk();

    expect($transcript->refresh()->status)->toBe(TranscriptStatus::Submitted); // untouched, retried later
    Queue::assertPushed(ProcessSttWebhookJob::class, 1);
});

it('fails a recording with no audio with a clear message instead of a provider error', function () {
    $session = CaptureSession::factory()->create();
    $recording = $session->recordings()->create([
        'role' => RecordingRole::Primary, 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2,
        'declared_size_bytes' => 98, 'sha256' => str_repeat('a', 64), 'chunk_size_bytes' => 98, 'total_chunks' => 1,
        'received_chunks' => [0], 'upload_status' => UploadStatus::Ready, 'stored_path' => 'files/x.elr',
    ]);
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Pending]);

    (new TranscribeFinalJob($transcript->id))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));

    $transcript->refresh();
    expect($transcript->status)->toBe(TranscriptStatus::Failed);
    expect($transcript->error)->toContain('No audio was captured');
    expect($recording->exists)->toBeTrue();
});
