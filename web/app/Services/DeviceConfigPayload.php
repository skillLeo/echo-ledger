<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttUseCase;
use App\Models\Device;
use App\Services\Usage\UsageSummary;
use App\Stt\SttManager;

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
            'pins' => $this->settings->pins(),
            'usage' => app(UsageSummary::class)->toArray(),
        ];
    }
}
