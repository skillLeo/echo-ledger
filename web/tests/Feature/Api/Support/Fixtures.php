<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Support;

use Illuminate\Support\Str;

/** Request bodies and provider payloads shared by the API integration tests. */
final class Fixtures
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function artifact(string $bytes, array $overrides = []): array
    {
        return array_merge([
            'role' => 'primary',
            'mime' => 'audio/ogg',
            'codec' => 'opus',
            'channels' => 2,
            'sample_rate' => 48000,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'duration_ms' => 60000,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function sessionBody(string $bytes, array $overrides = [], array $artifact = []): array
    {
        return array_merge([
            'client_ref' => (string) Str::uuid(),
            'source_app' => 'whatsapp',
            'client_id' => null,
            'title' => null,
            'started_at' => '2026-10-24T09:00:00Z',
            'ended_at' => '2026-10-24T09:01:00Z',
            'duration_ms' => 60000,
            'language' => null,
            'artifacts' => [self::artifact($bytes, $artifact)],
        ], $overrides);
    }

    /**
     * Body of an open session (no `artifacts` key), docs/API.md "Open session".
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function openSessionBody(array $overrides = []): array
    {
        return array_merge([
            'client_ref' => (string) Str::uuid(),
            'source_app' => 'google_meet',
            'client_id' => null,
            'title' => null,
            'started_at' => '2026-10-24T09:00:00Z',
            'language' => null,
        ], $overrides);
    }

    /**
     * Body of POST /api/sessions/{id}/finish for one primary artifact of $bytes.
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function finishBody(string $bytes, array $overrides = [], array $artifact = []): array
    {
        return array_merge([
            'ended_at' => '2026-10-24T09:42:10Z',
            'duration_ms' => 2530000,
            'artifacts' => [self::artifact($bytes, $artifact)],
        ], $overrides);
    }

    /**
     * Audio-looking bytes with a recognisable marker, so a test can prove the marker never reaches disk in clear.
     */
    public static function audio(int $length, string $marker = 'PLAINTEXT-MARKER-0042'): string
    {
        $head = 'OggS'.$marker;

        return $head.random_bytes(max(0, $length - strlen($head)));
    }

    /** @return list<string> */
    public static function chunks(string $bytes, int $size): array
    {
        return $bytes === '' ? [''] : str_split($bytes, $size);
    }

    /**
     * A finished two-channel AssemblyAI transcript (GET /v2/transcript/{id}).
     *
     * @return array<string, mixed>
     */
    public static function assemblyAiResult(string $jobId = 'job_1'): array
    {
        return [
            'id' => $jobId,
            'status' => 'completed',
            'audio_channels' => 2,
            'language_code' => 'en',
            'audio_duration' => 12,
            'utterances' => [
                ['channel' => '1', 'start' => 0, 'end' => 900, 'text' => 'Hello there.', 'words' => []],
                ['channel' => '2', 'start' => 1000, 'end' => 2000, 'text' => 'Hi, thanks for calling.', 'words' => []],
            ],
        ];
    }

    /**
     * One valid device-captured caption segment.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function segment(array $overrides = []): array
    {
        return array_merge([
            'channel' => 1,
            'speaker' => 'Me',
            'start_ms' => 250,
            'end_ms' => 2600,
            'text' => 'Smoke from the north.',
            'words' => [['text' => 'Smoke', 'start_ms' => 250, 'end_ms' => 650, 'confidence' => 0.97]],
        ], $overrides);
    }

    /** ISO-8601 with a numeric offset or Z, as the device apps parse it. */
    public const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public const ULID = '/^[0-9a-hjkmnp-tv-z]{26}$/i';
}
