<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Models\Transcript;
use App\Stt\Contracts\SttProvider;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use App\Stt\Exceptions\UnsupportedFeature;
use Illuminate\Http\Request;

/**
 * NVIDIA Parakeet TDT 0.6B v3 via sherpa-onnx runs ON THE DEVICE. The server never runs inference: it marks the
 * transcript `awaiting_device` and accepts the device's upload at POST /api/sessions/{id}/transcripts (kind=final).
 * Covers 25 European languages only (no Urdu/Hindi/Arabic) — see docs/research/stt-azure-local.md R4.
 */
final class ParakeetLocalDriver implements SttProvider
{
    public function key(): SttProviderKey
    {
        return SttProviderKey::ParakeetLocal;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(CompletionMode::Device, 'on_device', false, false, [], 0, 0, 0, 0, 0);
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        return new StreamingGrant('parakeet_local', 'on_device', null, [], []);
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        return SubmitResult::awaitingDevice();
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        throw new UnsupportedFeature('On-device engine; no webhooks.');
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        return null;
    }

    public function deleteRemote(Transcript $transcript): void {}
}
