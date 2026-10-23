<?php

declare(strict_types=1);

namespace App\Stt\Contracts;

use App\Enums\SttProviderKey;
use App\Models\Transcript;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use Illuminate\Http\Request;

/**
 * One speech-to-text engine. Drivers hold no state between calls and read secrets via CredentialVault.
 * Seam for future text features (e.g. summaries) is intentionally NOT here: v1 is transcription only.
 */
interface SttProvider
{
    public function key(): SttProviderKey;

    public function capabilities(): ProviderCapabilities;

    /** Short-lived device credentials for live captions/dictation. Never returns the provider API key. */
    public function streamingGrant(StreamingRequest $request): StreamingGrant;

    /** Submits (or for sync providers, performs) the final transcription of a decrypted recording. */
    public function submitFinal(FinalJobRequest $request): SubmitResult;

    /** Verifies authenticity of an inbound callback. Throws InvalidWebhookSignature. */
    public function verifyWebhook(Request $request): WebhookEnvelope;

    /**
     * Returns the finished transcript, or null while the provider is still working.
     * Throws ProviderJobFailed when the provider reports an error.
     *
     * @param  array<string, mixed>|null  $webhookPayload  verified webhook body when available
     */
    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript;

    /** Deletes the provider-side copy (transcript and uploaded audio where the API allows). */
    public function deleteRemote(Transcript $transcript): void;
}
