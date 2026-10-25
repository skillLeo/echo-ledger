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
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SessionLifecycle;
use App\Services\TranscriptStore;
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
            ...self::artifactRules('artifacts.*.'),
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

        $session = $withArtifacts ? $lifecycle->createWithArtifacts($device, $data) : $lifecycle->open($device, $data);

        return $this->sessionCreatedResponse($session, $stt, 201);
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
            'duration_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'title' => ['nullable', 'string', 'max:200'],
            'client_id' => ['nullable', 'string', 'size:26'],
            'artifacts' => ['required', 'array', 'min:1', 'max:3'],
            ...self::artifactRules('artifacts.*.'),
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
    public function show(Request $request, string $id): JsonResponse
    {
        $session = $this->findSession($request, $id)->load(['device', 'client', 'transcripts', 'recordings']);
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
            'playback_url' => $primary?->isReady() ? URL::temporarySignedRoute('api.recordings.audio',
                now()->addMinutes((int) config('echoledger.playback_url_minutes', 30)), ['recording' => $primary->id]) : null,
        ]);
    }

    /** DELETE /api/sessions/{id} — tombstone + immediate media erasure. */
    public function destroy(Request $request, string $id): Response
    {
        $this->findSession($request, $id)->delete();

        return response()->noContent();
    }

    /** POST /api/sessions/{id}/transcripts — device live transcript or on-device final transcript. */
    public function storeTranscript(Request $request, string $id, TranscriptStore $store, FinalTranscriptCoordinator $coordinator, SttManager $stt): JsonResponse
    {
        $session = $this->findSession($request, $id);
        $data = $request->validate([
            'kind' => ['required', Rule::enum(TranscriptKind::class)],
            'provider' => ['required', 'string', 'max:32'],
            'language' => ['nullable', 'string', 'max:16'],
            'segments' => ['present', 'array', 'max:20000'],
            'segments.*.channel' => ['required', 'integer', 'in:1,2'],
            'segments.*.speaker' => ['nullable', 'string', 'max:40'],
            'segments.*.start_ms' => ['required', 'integer', 'min:0'],
            'segments.*.end_ms' => ['required', 'integer', 'gte:segments.*.start_ms'],
            'segments.*.text' => ['required', 'string', 'max:20000'],
            'segments.*.words' => ['nullable', 'array'],
            'segments.*.words.*' => ['array'],
            'segments.*.words.*.text' => ['nullable', 'string', 'max:1000'],
            'segments.*.words.*.start_ms' => ['nullable', 'integer', 'min:0'],
            'segments.*.words.*.end_ms' => ['nullable', 'integer', 'min:0'],
            'segments.*.words.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $kind = TranscriptKind::from($data['kind']);
        if ($kind === TranscriptKind::Final) {
            $provider = $coordinator->providerFor($session);
            if ($stt->driver($provider)->capabilities()->mode !== CompletionMode::Device) {
                throw ValidationException::withMessages(['kind' => 'This session is transcribed by the server provider.']);
            }
        }

        $maxEnd = $session->duration_ms !== null ? $session->duration_ms + 500 : PHP_INT_MAX;
        $channels = count(array_unique(array_column($data['segments'], 'channel')));
        $segments = [];
        foreach ($data['segments'] as $raw) {
            if ((int) $raw['end_ms'] > $maxEnd) {
                throw ValidationException::withMessages(['segments' => 'Segment timing exceeds the session duration.']);
            }
            $raw['speaker'] = $raw['speaker'] ?? TranscriptNormalizer::speakerFor((int) $raw['channel'], max($channels, $session->recordings()->max('channels') ?? 1));
            $segments[] = Segment::fromArray($raw);
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

    private function findSession(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();

        return CaptureSession::query()->where('user_id', $device->user_id)->findOrFail($id);
    }
}
