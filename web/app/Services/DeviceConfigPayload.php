<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttUseCase;
use App\Models\Device;
use App\Services\Usage\UsageSummary;
use App\Stt\LanguageChoice;
use App\Stt\SttManager;
use App\Support\UnsureMarkers;

/** Body of GET /api/config (docs/API.md). Never contains secrets. */
final class DeviceConfigPayload
{
    /** Contract version the server speaks; clients fall back to the v1 flows when it is absent. */
    public const API_VERSION = '1.1';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SttManager $stt,
    ) {}

    /** @return array<string, mixed> */
    public function build(Device $device): array
    {
        $providers = [];
        foreach (SttUseCase::cases() as $useCase) {
            $key = $this->settings->providerFor($useCase);
            $caps = $this->stt->driver($key)->capabilities();
            $entry = ['provider' => $key->value];
            if ($useCase->isStreaming()) {
                $entry['mode'] = $caps->liveMode;
            } else {
                $entry['mode'] = $caps->mode->value;
                $entry['required_artifacts'] = $this->stt->requiredArtifacts($key);
                $entry['preferred_primary'] = $this->stt->preferredPrimary($key);
            }
            $providers[$useCase->value] = $entry;
        }

        return [
            'api_version' => self::API_VERSION,
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'platform' => $device->platform->value,
                'source_device' => $device->sourceDevice()->value,
            ],
            'server_time' => now()->toAtomString(),
            'retention_days' => $this->settings->retentionDays(),
            'upload' => [
                'chunk_size_bytes' => ChunkedUploadService::advertisedChunkSize(),
                'max_recording_bytes' => (int) config('echoledger.max_recording_bytes'),
                'accepted_mimes' => array_values((array) config('echoledger.accepted_mimes')),
            ],
            'providers' => $providers,
            'captions' => ['channels' => $this->settings->captionChannels()],
            'markers' => ['tags' => $this->settings->markerTags()],
            // Since v1.3 (plan A4): how unsure words appear in copied text ([?word] / word(?) / off), below which
            // confidence a word is unsure, and whether copies start with the note line ("Copy for AI" always does).
            'unsure' => UnsureMarkers::settings($this->settings) + ['note_text' => UnsureMarkers::note(UnsureMarkers::settings($this->settings)['style'])],
            // Since v1.1.2: the language choices (value, label, chip) and the console default, so every app offers the
            // same list on its language chip. A null value = automatic.
            // Since v1.2: Auto, English, Urdu, Arabic first, then the "More languages" (`more`: true); `auto` lists the
            // languages Auto may pick. Hindi and the mixed choices are gone (they read as Urdu).
            'languages' => [
                'default' => LanguageChoice::normalize($this->settings->get('default_language')),
                'options' => array_map(fn (string $value): array => [
                    'value' => $value === '' ? null : $value,
                    'label' => LanguageChoice::OFFERED[$value],
                    'chip' => LanguageChoice::CHIPS[$value],
                    'more' => array_key_exists($value, LanguageChoice::MORE),
                ], array_keys(LanguageChoice::OFFERED)),
                'auto' => LanguageChoice::autoLanguages($this->settings),
            ],
            'pins' => $this->settings->pins(),
            'usage' => app(UsageSummary::class)->toArray($device->workspace_id),
        ];
    }
}
