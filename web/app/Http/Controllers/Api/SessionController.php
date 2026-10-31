<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CompletionMode;
use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Http\Resources\RecordingUploadResource;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Models\SessionMarker;
use App\Models\SessionOpen;
use App\Models\Transcript;
use App\Services\Callers\CallerMatcher;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use App\Services\Retranscriber;
use App\Services\SessionLifecycle;
use App\Services\SessionMarkers;
use App\Services\TranscriptEditor;
use App\Services\TranscriptStore;
use App\Services\Workspaces\Workspaces;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\SttManager;
use App\Stt\TranscriptNormalizer;
use Closure;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class SessionController extends Controller
{
    /** @return array<string, mixed> */
    public static function artifactRules(string $prefix = ''): array
    {
        return [
            $prefix.'role' => ['required', Rule::enum(RecordingRole::class)],
            $prefix.'mime' => ['required', 'string', Rule::in((array) config('echoledger.accepted_mimes'))],
            $prefix.'codec' => ['nullable', 'string', 'max:32'],
            $prefix.'channels' => ['required', 'integer', 'min:1', 'max:2'],
            $prefix.'sample_rate' => ['nullable', 'integer', 'min:8000', 'max:192000'],
            $prefix.'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('echoledger.max_recording_bytes')],
            $prefix.'sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            $prefix.'duration_ms' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * A sample rate of 0 means "unknown" (a shared voice note has no known rate); treat it as absent rather than
     * rejecting the whole upload. Older app versions send 0. Nothing else is coerced: text or fractions fail validation.
     *
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public static function normalizeArtifact(array $artifact): array
    {
        if (array_key_exists('sample_rate', $artifact) && ($artifact['sample_rate'] === 0 || $artifact['sample_rate'] === '0')) {
            $artifact['sample_rate'] = null;
        }

        return $artifact;
    }

    /**
     * POST /api/sessions — idempotent on (device, client_ref). With `artifacts`: one-shot create (status `uploading`);
     * without the key: open session (status `recording`, docs/API.md "Session lifecycle").
     */
    public function store(Request $request, SessionLifecycle $lifecycle, SttManager $stt): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $withArtifacts = $request->exists('artifacts');
        $this->normalizeArtifactsInput($request);
        $data = $request->validate([
            'client_ref' => ['required', 'string', 'max:64'],
            'source_app' => ['required', Rule::enum(SourceApp::class)],
            'client_id' => ['nullable', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:200'],
            'started_at' => ['required', ...self::instantRules()],
            'ended_at' => ['nullable', ...self::instantRules(), self::notBefore($request->input('started_at'), 'started at')],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'language' => ['nullable', 'string', 'max:16'],
            'artifacts' => ['sometimes', 'array', 'min:1', 'max:3'],
            'skip_duplicate' => ['sometimes', 'boolean'],
            ...self::artifactRules('artifacts.*.'),
            ...SessionMarkers::rules(),
            ...CallerMatcher::rules(),
        ]);
        if ($withArtifacts) {
            $this->assertArtifactRoles($data['artifacts']);
        }

        $existing = CaptureSession::withTrashed()->where('device_id', $device->id)->where('client_ref', $data['client_ref'])->first();
        if ($existing !== null) {
            // An app that lost the answer of an open session (or crashed) and now sends the one-shot create with the
            // same client_ref finishes that session. A tombstone is returned unchanged.
            if ($withArtifacts && ! $existing->trashed() && $existing->status === SessionStatus::Recording) {
                $existing = $lifecycle->finish($device, $existing, $data);
            }

            return $this->sessionCreatedResponse($existing, $stt, 200);
        }

        // Imports (since v1.4): a file whose audio is already in the workspace's Library is skipped, not stored twice.
        if ($withArtifacts && ($data['skip_duplicate'] ?? false)) {
            $duplicate = $this->duplicateOf($device, $data['artifacts']);
            if ($duplicate !== null) {
                throw new ApiError(409, 'duplicate_recording', 'This file is already in your Library.', ['session_id' => $duplicate]);
            }
        }

        $session = $withArtifacts ? $lifecycle->createWithArtifacts($device, $data) : $lifecycle->open($device, $data);

        return $this->sessionCreatedResponse($session, $stt, 201);
    }

    /**
     * The id of a live recording of this workspace whose primary file has the same SHA-256 as the one about to be
     * uploaded, or null. Deleted recordings (tombstones and the undo bin) do not count.
     *
     * @param  list<array<string, mixed>>  $artifacts
     */
    private function duplicateOf(Device $device, array $artifacts): ?string
    {
        $primary = collect($artifacts)->firstWhere('role', RecordingRole::Primary->value);
        $sha = is_array($primary) ? strtolower((string) ($primary['sha256'] ?? '')) : '';
        if ($sha === '') {
            return null;
        }
        $found = Recording::query()
            ->where('role', RecordingRole::Primary->value)
            ->where('sha256', $sha)
            ->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id))
            ->value('capture_session_id');

        return is_string($found) ? $found : null;
    }

    /** POST /api/sessions/{id}/finish — attach the artifacts of an open session (creator device only; idempotent). */
    public function finish(Request $request, string $id, SessionLifecycle $lifecycle, SttManager $stt): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $session = CaptureSession::query()->where('device_id', $device->id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        $this->normalizeArtifactsInput($request);
        $data = $request->validate([
            'ended_at' => ['required', ...self::instantRules(), self::notBefore($session->started_at, 'the start of the recording')],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'title' => ['nullable', 'string', 'max:200'],
            'client_id' => ['nullable', 'string', 'size:26'],
            'language' => ['nullable', 'string', 'max:16'],
            'artifacts' => ['required', 'array', 'min:1', 'max:3'],
            ...self::artifactRules('artifacts.*.'),
            ...SessionMarkers::rules(),
            ...CallerMatcher::rules(),
        ]);
        $this->assertArtifactRoles($data['artifacts']);

        return $this->sessionCreatedResponse($lifecycle->finish($device, $session, $data), $stt, 200);
    }

    /**
     * An ISO-8601 instant sent as text (any offset). Numbers, lists and unparsable text are a 422, never a server error.
     *
     * @return list<mixed>
     */
    public static function instantRules(): array
    {
        return ['bail', 'string', 'date', static function (string $attribute, mixed $value, Closure $fail): void {
            try {
                Carbon::parse((string) $value);
            } catch (Throwable) {
                $fail('The :attribute field must be a valid date.');
            }
        }];
    }

    /**
     * The instant must not be before $start. Laravel's after_or_equal would parse a numeric start field with a
     * TypeError (a server error), so the comparison only runs when both sides are parsable text or a date.
     */
    private static function notBefore(mixed $start, string $startLabel): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($start, $startLabel): void {
            if (! is_string($value) || (! is_string($start) && ! $start instanceof DateTimeInterface)) {
                return;
            }
            try {
                if (Carbon::parse($value)->lt(Carbon::parse($start))) {
                    $fail("The :attribute field must be a date after or equal to {$startLabel}.");
                }
            } catch (Throwable) {
                // An unparsable start is reported on its own field.
            }
        };
    }

    private function normalizeArtifactsInput(Request $request): void
    {
        $artifacts = $request->input('artifacts');
        if (is_array($artifacts)) {
            $request->merge(['artifacts' => array_map(
                fn ($artifact) => is_array($artifact) ? self::normalizeArtifact($artifact) : $artifact,
                $artifacts,
            )]);
        }
    }

    /** @param  array<int|string, mixed>  $artifacts  validated artifacts */
    private function assertArtifactRoles(array $artifacts): void
    {
        $roles = array_column($artifacts, 'role');
        if (! in_array(RecordingRole::Primary->value, $roles, true) || count($roles) !== count(array_unique($roles))) {
            throw ValidationException::withMessages(['artifacts' => 'Exactly one primary artifact and unique roles are required.']);
        }
    }

    private function sessionCreatedResponse(CaptureSession $session, SttManager $stt, int $status): JsonResponse
    {
        $provider = SttProviderKey::tryFrom((string) $session->final_provider) ?? SttProviderKey::AssemblyAi;

        return response()->json([
            'session' => new LibraryItemResource($session->load(['device', 'client', 'transcripts'])),
            'recordings' => RecordingUploadResource::collection($session->recordings()->get()),
            'required_artifacts' => $stt->requiredArtifacts($provider),
        ], $status);
    }

    /** POST /api/sessions/{id}/recordings */
    public function addRecording(Request $request, string $id, ChunkedUploadService $uploads): JsonResponse
    {
        $session = $this->findSession($request, $id);
        $request->merge(self::normalizeArtifact($request->all()));
        $data = $request->validate(self::artifactRules());
        if ($session->recordings()->where('role', $data['role'])->exists()) {
            throw ValidationException::withMessages(['role' => 'This artifact role already exists for the session.']);
        }
        /** @var Device $device */
        $device = $request->user();
        $uploads->assertOpenUploadCapacity($device, 1);
        $recording = $uploads->createRecording($session, $data, ChunkedUploadService::advertisedChunkSize());

        return (new RecordingUploadResource($recording))->response()->setStatusCode(201);
    }

    /** GET /api/sessions/{id} */
    /**
     * POST /api/sessions/{id}/opened (since v1.3): the device's user has opened this recording, so the apps stop
     * showing it as new. Idempotent; does not touch the recording itself (no sync, no reordering).
     */
    public function opened(Request $request, string $id): Response
    {
        /** @var Device $device */
        $device = $request->user();
        SessionOpen::mark($this->findSession($request, $id)->id, $device->user_id);

        return response()->noContent();
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->findSession($request, $id)->load(['device', 'client', 'transcripts', 'recordings', 'markers']);
        $item = (new LibraryItemResource($session))->toArray($request);
        $primary = $session->recordings->first(fn (Recording $r) => $r->role === RecordingRole::Primary);

        return response()->json([
            ...$item,
            'recordings' => $session->recordings->map(fn (Recording $r): array => [
                'id' => $r->id, 'role' => $r->role->value, 'mime' => $r->mime, 'size_bytes' => $r->declared_size_bytes,
                'duration_ms' => $r->duration_ms, 'status' => $r->upload_status->value,
            ])->values(),
            'transcripts' => $session->transcripts->map(fn (Transcript $t): array => [
                'id' => $t->id, 'kind' => $t->kind->value, 'provider' => $t->provider, 'status' => $t->status->value, 'language' => $t->language,
                ...TranscriptResource::failure($t),
            ])->values(),
            'markers' => $session->markers->map(fn (SessionMarker $m): array => $m->toApi())->values(),
            'pauses' => $session->pauses ?? [],
            'language_marks' => $session->language_marks ?? [],
            // Since v1.2: who the recording was with, as the device saw them.
            'caller' => self::caller($session),
            'speaker_names' => (object) app(TranscriptEditor::class)->speakerNames($session),
            'playback_url' => $primary?->isReady() ? URL::temporarySignedRoute('api.recordings.audio',
                now()->addMinutes((int) config('echoledger.playback_url_minutes', 30)), ['recording' => $primary->id]) : null,
        ]);
    }

    /**
     * POST /api/sessions/{id}/retranscribe — "Re-transcribe as <language>" (since v1.2): `{"language": "ur"}` (null =
     * Auto). 202 with the run that was started; the current transcript stays until the new one is ready.
     */
    public function retranscribe(Request $request, string $id): JsonResponse
    {
        $session = $this->findSession($request, $id);
        $data = $request->validate(['language' => ['present', 'nullable', 'string', 'max:16']]);
        try {
            $run = app(Retranscriber::class)->startAs($session, $data['language']);
        } catch (RuntimeException $e) {
            throw new ApiError(409, 'cannot_retranscribe', $e->getMessage());
        }

        return response()->json(['transcript' => [
            'id' => $run->id, 'kind' => $run->kind->value, 'provider' => $run->provider, 'status' => $run->status->value, 'language' => $run->language,
        ]], 202);
    }

    /** @return array{name: string|null, number: string|null, source: string|null}|null */
    public static function caller(CaptureSession $session): ?array
    {
        return $session->caller_name === null && $session->caller_number === null ? null
            : ['name' => $session->caller_name, 'number' => $session->caller_number, 'source' => $session->caller_source];
    }

    /** POST /api/sessions/{id}/markers — a bookmark added after the call (since v1.1). */
    public function storeMarker(Request $request, string $id): JsonResponse
    {
        $session = $this->findSession($request, $id);
        $data = $request->validate(SessionMarkers::markerRules());
        if ($session->markers()->count() >= SessionMarkers::MAX_MARKERS) {
            throw new ApiError(409, 'too_many_markers', 'This recording already has the most bookmarks it can hold.');
        }
        $marker = $session->markers()->create([
            'at_ms' => (int) $data['at_ms'],
            'tag' => SessionMarkers::cleanTag($data['tag'] ?? null),
            'note' => SessionMarkers::cleanText($data['note'] ?? null),
            'source' => 'device',
        ]);

        return response()->json($marker->toApi(), 201);
    }

    /** DELETE /api/sessions/{id}/markers/{marker} (since v1.1). */
    public function destroyMarker(Request $request, string $id, string $marker): Response
    {
        $session = $this->findSession($request, $id);
        $found = $session->markers()->whereKey($marker)->first();
        if (! $found instanceof SessionMarker) {
            throw new ApiError(404, 'marker_not_found', 'Bookmark not found.');
        }
        $found->delete();

        return response()->noContent();
    }

    /** DELETE /api/sessions/{id} — tombstone + immediate media erasure. */
    public function destroy(Request $request, string $id): Response
    {
        $session = $this->findSession($request, $id);
        /** @var Device $device */
        $device = $request->user();
        app(Workspaces::class)->assertCanDeleteSession($device->user, $session);
        $session->delete();

        return response()->noContent();
    }

    /** POST /api/sessions/{id}/transcripts — device live transcript or on-device final transcript. */
    public function storeTranscript(Request $request, string $id, TranscriptStore $store, FinalTranscriptCoordinator $coordinator, SttManager $stt): JsonResponse
    {
        $session = $this->findSession($request, $id);
        // Only the top level goes through the validator: wildcard rules over 20 000 segments take minutes, so the
        // segments are checked in one linear pass below (same rules, first error reported as segments.<i>.<field>).
        $data = $request->validate([
            'kind' => ['required', Rule::enum(TranscriptKind::class)],
            'provider' => ['required', 'string', 'max:32'],
            'language' => ['nullable', 'string', 'max:16'],
            'segments' => ['present', 'array', 'max:'.self::MAX_SEGMENTS],
        ]);
        $maxEnd = $session->duration_ms !== null ? $session->duration_ms + 500 : PHP_INT_MAX;
        $rows = self::validatedSegments(array_values($data['segments']), $maxEnd);

        $kind = TranscriptKind::from($data['kind']);
        if ($kind === TranscriptKind::Final) {
            $provider = $coordinator->providerFor($session);
            if ($stt->driver($provider)->capabilities()->mode !== CompletionMode::Device) {
                throw ValidationException::withMessages(['kind' => 'This session is transcribed by the server provider.']);
            }
        }

        $channels = max(count(array_unique(array_column($rows, 'channel'))), (int) ($session->recordings()->max('channels') ?? 1));
        $segments = [];
        foreach ($rows as $row) {
            $row['speaker'] ??= TranscriptNormalizer::speakerFor($row['channel'], $channels);
            $segments[] = Segment::fromArray($row);
        }
        $segments = TranscriptNormalizer::sortByStart($segments);

        $transcript = $session->transcripts()->firstOrNew(['kind' => $kind]);
        $transcript->fill([
            'provider' => $data['provider'],
            'status' => TranscriptStatus::Processing,
            'language' => $data['language'] ?? null,
        ])->save();
        $store->complete($transcript, new NormalizedTranscript(TranscriptNormalizer::plainText($segments), $segments, $data['language'] ?? null,
            ['source' => 'device']));

        return (new TranscriptResource($transcript->refresh()))->response()->setStatusCode(201);
    }

    /** Most segments one transcript upload may carry (docs/API.md). */
    public const MAX_SEGMENTS = 20000;

    /**
     * Checks every segment in one pass: channel 1|2, 0 ≤ start_ms ≤ end_ms ≤ $maxEnd, text 1-20 000 characters,
     * speaker ≤ 40, optional words (text ≤ 1000, times ≥ 0, confidence 0-1). The first problem is a 422 on
     * `segments.<i>.<field>`.
     *
     * @param  list<mixed>  $segments
     * @return list<array{channel: int, speaker: string|null, start_ms: int, end_ms: int, text: string, words: list<array<string, mixed>>}>
     */
    private static function validatedSegments(array $segments, int $maxEnd): array
    {
        $fail = static function (string $key, string $message): never {
            throw ValidationException::withMessages([$key => str_replace(':attribute', $key, $message)]);
        };
        $rows = [];
        foreach ($segments as $i => $segment) {
            $at = "segments.{$i}";
            if (! is_array($segment)) {
                $fail($at, 'The :attribute field must be an object.');
            }

            $channel = self::integerOrNull($segment['channel'] ?? null);
            if (($segment['channel'] ?? null) === null) {
                $fail("{$at}.channel", 'The :attribute field is required.');
            }
            if ($channel !== 1 && $channel !== 2) {
                $fail("{$at}.channel", 'The selected :attribute is invalid.');
            }

            $speaker = $segment['speaker'] ?? null;
            if ($speaker !== null && (! is_string($speaker) || mb_strlen($speaker) > 40)) {
                $fail("{$at}.speaker", 'The :attribute field must be a string of at most 40 characters.');
            }

            $start = self::integerOrNull($segment['start_ms'] ?? null);
            if ($start === null || $start < 0) {
                $fail("{$at}.start_ms", 'The :attribute field must be an integer of at least 0.');
            }
            $end = self::integerOrNull($segment['end_ms'] ?? null);
            if ($end === null || $end < $start) {
                $fail("{$at}.end_ms", 'The :attribute field must be an integer greater than or equal to the start.');
            }
            if ($end > $maxEnd) {
                $fail("{$at}.end_ms", 'Segment timing exceeds the session duration.');
            }

            $text = $segment['text'] ?? null;
            if (! is_string($text) || trim($text) === '') {
                $fail("{$at}.text", 'The :attribute field is required.');
            }
            if (mb_strlen($text) > 20000) {
                $fail("{$at}.text", 'The :attribute field must not be greater than 20000 characters.');
            }

            $rows[] = [
                'channel' => $channel, 'speaker' => $speaker, 'start_ms' => $start, 'end_ms' => $end, 'text' => $text,
                'words' => self::validatedWords($segment['words'] ?? null, "{$at}.words", $fail),
            ];
        }

        return $rows;
    }

    /**
     * @param  callable(string, string): never  $fail
     * @return list<array<string, mixed>>
     */
    private static function validatedWords(mixed $words, string $at, callable $fail): array
    {
        if ($words === null) {
            return [];
        }
        if (! is_array($words)) {
            $fail($at, 'The :attribute field must be an array.');
        }
        $valid = [];
        foreach (array_values($words) as $j => $word) {
            if (! is_array($word)) {
                $fail("{$at}.{$j}", 'The :attribute field must be an array.');
            }
            $text = $word['text'] ?? null;
            if ($text !== null && (! is_string($text) || mb_strlen($text) > 1000)) {
                $fail("{$at}.{$j}.text", 'The :attribute field must be a string of at most 1000 characters.');
            }
            foreach (['start_ms', 'end_ms'] as $field) {
                $value = $word[$field] ?? null;
                if ($value !== null && (self::integerOrNull($value) ?? -1) < 0) {
                    $fail("{$at}.{$j}.{$field}", 'The :attribute field must be an integer of at least 0.');
                }
            }
            $confidence = $word['confidence'] ?? null;
            if ($confidence !== null && (is_bool($confidence) || ! is_numeric($confidence) || ! is_finite((float) $confidence)
                || (float) $confidence < 0 || (float) $confidence > 1)) {
                $fail("{$at}.{$j}.confidence", 'The :attribute field must be a number between 0 and 1.');
            }
            $valid[] = $word;
        }

        return $valid;
    }

    /** Laravel's `integer` rule (FILTER_VALIDATE_INT, so 5, "5" and 5.0 pass) without letting booleans through. */
    private static function integerOrNull(mixed $value): ?int
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false ? null : $int;
    }

    private function findSession(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();

        return CaptureSession::query()->where('workspace_id', $device->workspace_id)->findOrFail($id);
    }
}
