<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\ProcessSttWebhookJob;
use App\Models\CaptureSession;
use App\Models\WebhookEvent;
use App\Services\CredentialVault;
use App\Stt\Drivers\AssemblyAiDriver;
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

it('accepts a valid AssemblyAI webhook once and is idempotent on replay', function () {
    Queue::fake();
    app(CredentialVault::class)->put('assemblyai', 'webhook_secret', 'whsec_secret');

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
    $post()->assertOk();

    expect(WebhookEvent::count())->toBe(1);
    Queue::assertPushed(ProcessSttWebhookJob::class, 1);
});
