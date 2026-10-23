<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SttUseCase;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Services\SettingsRepository;
use App\Stt\Data\StreamingRequest;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\SttManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /api/stt/token — mints short-lived provider streaming credentials. Never returns provider API keys. */
final class SttTokenController extends Controller
{
    public function __invoke(Request $request, SttManager $stt, SettingsRepository $settings): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'use_case' => ['required', Rule::in([SttUseCase::LiveCaptions->value, SttUseCase::Dictation->value])],
            'client_id' => ['nullable', 'string', 'size:26'],
            'channels' => ['nullable', 'array', 'min:1', 'max:2'],
            'channels.*' => ['string', Rule::in(['me', 'client'])],
            'sample_rate' => ['nullable', 'integer', Rule::in([8000, 16000, 24000, 44100, 48000])],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        $useCase = SttUseCase::from($data['use_case']);
        $channels = $useCase === SttUseCase::Dictation ? ['me'] : array_values(array_unique($data['channels'] ?? ['me', 'client']));
        if ($useCase === SttUseCase::LiveCaptions && $settings->captionChannels() === 'client_only') {
            $channels = ['client'];
        }

        $client = null;
        if (! empty($data['client_id'])) {
            $client = Client::query()->where('user_id', $device->user_id)->findOrFail($data['client_id']);
        }

        if ($useCase === SttUseCase::LiveCaptions && $settings->captionChannels() === 'off') {
            return response()->json(['provider' => null, 'mode' => 'off', 'expires_at' => null, 'keyterms' => [], 'grants' => []]);
        }

        try {
            $grant = $stt->forUseCase($useCase)->streamingGrant(new StreamingRequest(
                useCase: $useCase,
                channels: $channels,
                sampleRate: (int) ($data['sample_rate'] ?? 16000),
                language: $data['language'] ?? ($client?->language ?: $settings->get('default_language')),
                keyterms: $stt->keytermsFor($client),
            ));
        } catch (ProviderNotConfigured $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (RequestException $e) {
            report($e);

            return response()->json(['message' => 'The speech provider rejected the token request.'], 502);
        }

        return response()->json($grant->toArray())->header('Cache-Control', 'no-store');
    }
}
