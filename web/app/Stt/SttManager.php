<?php

declare(strict_types=1);

namespace App\Stt;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\Client;
use App\Services\SettingsRepository;
use App\Stt\Contracts\SttProvider;
use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\Drivers\AzureMaiDriver;
use App\Stt\Drivers\ElevenLabsDriver;
use App\Stt\Drivers\GroqWhisperDriver;
use App\Stt\Drivers\ParakeetLocalDriver;
use App\Stt\Drivers\SonioxDriver;
use App\Stt\Drivers\WhisperCppLocalDriver;
use Illuminate\Contracts\Container\Container;

/** Resolves STT drivers per use-case (admin-selected on the Settings page). */
final class SttManager
{
    public function __construct(
        private readonly Container $container,
        private readonly SettingsRepository $settings,
    ) {}

    public function driver(SttProviderKey $key): SttProvider
    {
        return match ($key) {
            SttProviderKey::AssemblyAi => $this->container->make(AssemblyAiDriver::class),
            SttProviderKey::ElevenLabs => $this->container->make(ElevenLabsDriver::class),
            SttProviderKey::AzureMai => $this->container->make(AzureMaiDriver::class),
            SttProviderKey::WhisperCppLocal => $this->container->make(WhisperCppLocalDriver::class),
            SttProviderKey::ParakeetLocal => $this->container->make(ParakeetLocalDriver::class),
            SttProviderKey::Soniox => $this->container->make(SonioxDriver::class),
            SttProviderKey::Groq => $this->container->make(GroqWhisperDriver::class),
        };
    }

    public function forUseCase(SttUseCase $useCase): SttProvider
    {
        return $this->driver($this->settings->providerFor($useCase));
    }

    /**
     * The client's keyterms, then the global vocabulary (duplicates dropped). Each provider trims the list to what it
     * accepts, so the client's terms are kept first.
     *
     * @return list<string>
     */
    public function keytermsFor(?Client $client): array
    {
        return KeytermPolicy::sanitize([...($client?->keytermList() ?? []), ...app(SettingsRepository::class)->globalKeyterms()]);
    }

    /** @return list<string> roles devices should upload (docs/API.md GET /api/config) */
    public function requiredArtifacts(SttProviderKey $key): array
    {
        return $this->driver($key)->capabilities()->needsChannelArtifacts
            ? ['primary', 'channel_1', 'channel_2']
            : ['primary'];
    }

    /** ogg_opus | flac | wav */
    public function preferredPrimary(SttProviderKey $key): string
    {
        $mimes = $this->driver($key)->capabilities()->acceptedMimes;
        if ($mimes === [] || in_array('audio/ogg', $mimes, true)) {
            return 'ogg_opus';
        }

        return in_array('audio/flac', $mimes, true) ? 'flac' : 'wav';
    }

    /** Public webhook URL carrying our transcript id, or null when the app URL is not publicly reachable. */
    public function webhookUrl(SttProviderKey $key, string $transcriptId): ?string
    {
        $base = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);
        $local = $host === '' || $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($local || ! str_starts_with($base, 'https://')) {
            return null; // the reconciler polls instead (PLAN.md D11)
        }

        return $base.'/api/webhooks/stt/'.$key->value.'?rid='.rawurlencode($transcriptId);
    }
}
