<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SttUseCase;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Server side of the session lifecycle (docs/API.md "Session lifecycle"): open (status `recording`, only when a device
 * needs a live-caption token), finish (artifacts → upload slots, status `uploading`) and the sweep of stale open
 * sessions.
 */
final class SessionLifecycle
{
    /** A device may keep at most this many sessions in status `recording` (docs/API.md `too_many_open_sessions`). */
    public const MAX_OPEN_SESSIONS = 10;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ChunkedUploadService $uploads,
    ) {}

    /**
     * Create the session of a recording that has just started (no artifacts yet).
     *
     * @param  array<string, mixed>  $data  validated create body without `artifacts`
     */
    public function open(Device $device, array $data): CaptureSession
    {
        $open = CaptureSession::query()->where('device_id', $device->id)->where('status', SessionStatus::Recording->value)->count();
        if ($open >= self::MAX_OPEN_SESSIONS) {
            throw new ApiError(409, 'too_many_open_sessions', 'Too many recordings are still open. Finish or discard some first.');
        }

        return $this->create($device, $data, SessionStatus::Recording);
    }

    /**
     * Create a session and its upload slots in one step (the one-shot create).
     *
     * @param  array<string, mixed>  $data  validated create body with 1-3 `artifacts`
     */
    public function createWithArtifacts(Device $device, array $data): CaptureSession
    {
        $this->uploads->assertOpenUploadCapacity($device, count($data['artifacts']));

        return DB::transaction(function () use ($device, $data): CaptureSession {
            $session = $this->create($device, $data, SessionStatus::Uploading);
            $chunkSize = ChunkedUploadService::advertisedChunkSize();
            foreach ($data['artifacts'] as $artifact) {
                $this->uploads->createRecording($session, $artifact, $chunkSize);
            }
            $this->annotate($session, $data);

            return $session;
        });
    }

    /**
     * The length of a finished recording. A device that measured nothing leaves `duration_ms` out (the Android app does
     * for a recording of length 0), so fall back to the longest artifact, then to the span between start and end.
     *
     * @param  array<string, mixed>  $data
     */
    private static function finishDuration(array $data, CaptureSession $session): int
    {
        if (isset($data['duration_ms'])) {
            return (int) $data['duration_ms'];
        }
        $fromArtifacts = max(array_map(static fn (array $a): int => (int) ($a['duration_ms'] ?? 0), $data['artifacts'] ?? []) ?: [0]);
        if ($fromArtifacts > 0) {
            return $fromArtifacts;
        }
        $started = $session->started_at;
        $ended = self::utc($data['ended_at'] ?? null);

        return $ended === null ? 0 : max(0, (int) round($ended->diffInMilliseconds($started, true)));
    }

    /**
     * Attach the artifacts of an open session and move it to `uploading`. Idempotent: a session that already left
     * `recording` is returned unchanged.
     *
     * @param  array<string, mixed>  $data  validated finish body (`ended_at`, `duration_ms`, `artifacts`, optional `title` / `client_id`)
     */
    public function finish(Device $device, CaptureSession $session, array $data): CaptureSession
    {
        return DB::transaction(function () use ($device, $session, $data): CaptureSession {
            /** @var CaptureSession $locked */
            $locked = CaptureSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== SessionStatus::Recording) {
                return $locked;
            }

            $attributes = [
                'ended_at' => self::utc($data['ended_at']),
                'duration_ms' => self::finishDuration($data, $locked),
                'status' => SessionStatus::Uploading,
            ];
            if (array_key_exists('title', $data)) {
                $attributes['title'] = $data['title'];
            }
            if (array_key_exists('client_id', $data)) {
                $attributes['client_id'] = $this->resolveClientId($device, $data['client_id']);
            }
            // The language on the Keep card (since v1.1.2): it decides the final transcript's language and engine.
            if (array_key_exists('language', $data)) {
                $attributes['language'] = LanguageChoice::normalize($data['language']);
            }

            $this->uploads->assertOpenUploadCapacity($device, count($data['artifacts']));
            $chunkSize = ChunkedUploadService::advertisedChunkSize();
            foreach ($data['artifacts'] as $artifact) {
                $this->uploads->createRecording($locked, $artifact, $chunkSize);
            }
            $locked->update($attributes);
            $this->annotate($locked, $data);

            return $locked;
        });
    }

    /**
     * Stores the bookmarks and paused stretches sent with a recording (docs/API.md `markers` / `pauses`). A repeated
     * create or finish never adds the device's bookmarks twice.
     *
     * @param  array<string, mixed>  $data
     */
    private function annotate(CaptureSession $session, array $data): void
    {
        if (isset($data['pauses']) && is_array($data['pauses'])) {
            $session->pauses = array_values(array_map(
                static fn (array $pause): array => ['at_ms' => (int) $pause['at_ms'], 'paused_ms' => (int) $pause['paused_ms']],
                $data['pauses'],
            ));
            $session->save();
        }
        if (! empty($data['markers']) && ! $session->markers()->where('source', 'device')->exists()) {
            $session->markers()->createMany(array_map(static fn (array $marker): array => [
                'at_ms' => (int) $marker['at_ms'],
                'tag' => SessionMarkers::cleanTag($marker['tag'] ?? null),
                'note' => SessionMarkers::cleanText($marker['note'] ?? null),
                'source' => 'device',
            ], array_values($data['markers'])));
        }
    }

    /**
     * Tombstone sessions left in `recording` for $hours or longer without any recording (the app crashed or was
     * uninstalled). Returns the number swept.
     */
    public function sweepStale(int $hours = 6): int
    {
        $count = 0;
        CaptureSession::query()
            ->where('status', SessionStatus::Recording->value)
            ->where('created_at', '<=', now()->subHours($hours))
            ->doesntHave('recordings')
            ->lazyById(200)
            ->each(function (CaptureSession $session) use (&$count): void {
                $session->delete();
                $count++;
            });

        return $count;
    }

    /** The id of the caller's client, or null for an empty value; another owner's or an unknown id is a 404. */
    public function resolveClientId(Device $device, mixed $clientId): ?string
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        $client = Client::query()->where('workspace_id', $device->workspace_id)->find($clientId);
        if ($client === null) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }

        return $client->id;
    }

    /** Any ISO-8601 instant (any offset) as the UTC instant it names. */
    public static function utc(mixed $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse((string) $value)->utc();
    }

    /** @param  array<string, mixed>  $data */
    private function create(Device $device, array $data, SessionStatus $status): CaptureSession
    {
        $clientId = $this->resolveClientId($device, $data['client_id'] ?? null);
        $sourceApp = SourceApp::from($data['source_app']);
        $useCase = $sourceApp === SourceApp::VoiceNote ? SttUseCase::VoiceNote : SttUseCase::FinalTranscript;
        // The engine the language routes to (it decides which files the device should upload); the final transcript
        // routes again when it starts, with the language chosen by then.
        $router = app(LanguageRouter::class);
        $client = $clientId !== null ? Client::query()->find($clientId) : null;
        $language = LanguageChoice::normalize($data['language'] ?? null) ?? LanguageChoice::normalize($client?->language)
            ?? LanguageChoice::normalize($this->settings->get('default_language'));

        return CaptureSession::query()->create([
            'workspace_id' => $device->workspace_id,
            'user_id' => $device->user_id,
            'device_id' => $device->id,
            'client_id' => $clientId,
            'client_ref' => $data['client_ref'],
            'source_device' => $device->sourceDevice(),
            'source_app' => $sourceApp,
            'title' => $data['title'] ?? null,
            'language' => LanguageChoice::normalize($data['language'] ?? null),
            'started_at' => self::utc($data['started_at']),
            'ended_at' => self::utc($data['ended_at'] ?? null),
            'duration_ms' => $data['duration_ms'] ?? null,
            'status' => $status,
            'final_provider' => $router->final($language, $useCase)->provider->value,
        ]);
    }
}
