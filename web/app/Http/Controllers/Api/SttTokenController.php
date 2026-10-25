<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SessionStatus;
use App\Enums\SttUseCase;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Services\SettingsRepository;
use App\Services\Usage\BudgetGuard;
use App\Services\Workspaces\PlanLimits;
use App\Stt\Data\StreamingRequest;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\SttManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/stt/token — mints short-lived provider streaming credentials. Never returns provider API keys.
 * Checks run in the documented order: validation → session → client → captions off → monthly limit → provider.
 */
final class SttTokenController extends Controller
{
    /** Live captions are refused for a session this old or older (docs/API.md `session_not_recording`). */
    public const MAX_SESSION_HOURS = 4;

    public function __invoke(Request $request, SttManager $stt, SettingsRepository $settings, BudgetGuard $budget, PlanLimits $limits): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'use_case' => ['required', Rule::in([SttUseCase::LiveCaptions->value, SttUseCase::Dictation->value])],
            'session_id' => ['nullable', 'string', 'size:26'],
            'client_id' => ['nullable', 'string', 'size:26'],
            'channels' => ['nullable', 'array', 'min:1', 'max:2'],
            'channels.*' => ['string', Rule::in(['me', 'client'])],
            'sample_rate' => ['nullable', 'integer', Rule::in([8000, 16000, 24000, 44100, 48000])],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        $useCase = SttUseCase::from($data['use_case']);
        if ($useCase === SttUseCase::LiveCaptions) {
            $this->assertOpenSession($device, $data['session_id'] ?? null);
        }

        $channels = $useCase === SttUseCase::Dictation ? ['me'] : array_values(array_unique($data['channels'] ?? ['me', 'client']));
        if ($useCase === SttUseCase::LiveCaptions && $settings->captionChannels() === 'client_only') {
            $channels = ['client'];
        }

        $client = null;
        if (! empty($data['client_id'])) {
            $client = Client::query()->where('workspace_id', $device->workspace_id)->find($data['client_id']);
            if (! $client instanceof Client) {
                throw new ApiError(404, 'client_not_found', 'Client not found.');
            }
        }

        if ($useCase === SttUseCase::LiveCaptions && $settings->captionChannels() === 'off') {
            return response()->json(['provider' => null, 'mode' => 'off', 'expires_at' => null, 'keyterms' => [], 'grants' => []]);
        }

        if ($budget->limitReached()) {
            throw new ApiError(403, 'budget_exceeded', 'Monthly transcription limit reached');
        }
        $limits->assertMinutes($device->workspace_id);

        try {
            $grant = $stt->forUseCase($useCase)->streamingGrant(new StreamingRequest(
                useCase: $useCase,
                channels: $channels,
                sampleRate: (int) ($data['sample_rate'] ?? 16000),
                language: $data['language'] ?? ($client?->language ?: $settings->get('default_language')),
                keyterms: $stt->keytermsFor($client),
            ));
        } catch (ProviderNotConfigured $e) {
            throw new ApiError(503, 'provider_not_configured', $e->getMessage());
        } catch (RequestException $e) {
            report($e);

            throw new ApiError(502, 'provider_error', 'The speech provider rejected the token request.');
        } catch (ConnectionException $e) {
            report($e);

            throw new ApiError(502, 'provider_error', 'The speech provider could not be reached.');
        }

        return response()->json($grant->toArray())->header('Cache-Control', 'no-store');
    }

    /** Live captions need an open session of this device, still `recording` and younger than four hours. */
    private function assertOpenSession(Device $device, ?string $sessionId): void
    {
        if ($sessionId === null || $sessionId === '') {
            $message = 'The session id field is required for live captions.';

            throw new ApiError(422, 'session_required', $message, ['errors' => ['session_id' => [$message]]]);
        }

        $session = CaptureSession::query()->where('device_id', $device->id)->find($sessionId);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }
        if ($session->status !== SessionStatus::Recording || $session->created_at->lte(now()->subHours(self::MAX_SESSION_HOURS))) {
            throw new ApiError(409, 'session_not_recording', 'Live captions need an open recording session.');
        }
    }
}
