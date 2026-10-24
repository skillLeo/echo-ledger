<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CompletionMode;
use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Http\Resources\RecordingUploadResource;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\SttManager;
use App\Stt\TranscriptNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /** POST /api/sessions — idempotent on (device, client_ref). */
    public function store(Request $request, ChunkedUploadService $uploads, SettingsRepository $settings, SttManager $stt): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();
        $data = $request->validate([
            'client_ref' => ['required', 'string', 'max:64'],
            'source_app' => ['required', Rule::enum(SourceApp::class)],
            'client_id' => ['nullable', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:200'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'language' => ['nullable', 'string', 'max:16'],
            'artifacts' => ['required', 'array', 'min:1', 'max:3'],
            ...self::artifactRules('artifacts.*.'),
        ]);

        $roles = array_column($data['artifacts'], 'role');
        if (! in_array(RecordingRole::Primary->value, $roles, true) || count($roles) !== count(array_unique($roles))) {
            throw ValidationException::withMessages(['artifacts' => 'Exactly one primary artifact and unique roles are required.']);
        }

        $existing = CaptureSession::withTrashed()->where('device_id', $device->id)->where('client_ref', $data['client_ref'])->first();
        if ($existing !== null) {
            return $this->sessionCreatedResponse($existing, $stt, 200);
        }

        $clientId = null;
        if (! empty($data['client_id'])) {
            $clientId = Client::query()->where('user_id', $device->user_id)->findOrFail($data['client_id'])->id;
        }

        $sourceApp = SourceApp::from($data['source_app']);
        $useCase = $sourceApp === SourceApp::VoiceNote ? SttUseCase::VoiceNote : SttUseCase::FinalTranscript;
        $chunkSize = ChunkedUploadService::advertisedChunkSize();

        $session = DB::transaction(function () use ($device, $data, $clientId, $sourceApp, $settings, $useCase, $uploads, $chunkSize): CaptureSession {
            $session = CaptureSession::query()->create([
                'user_id' => $device->user_id,
                'device_id' => $device->id,
                'client_id' => $clientId,
                'client_ref' => $data['client_ref'],
                'source_device' => $device->sourceDevice(),
                'source_app' => $sourceApp,
                'title' => $data['title'] ?? null,
                'language' => $data['language'] ?? null,
                'started_at' => $data['started_at'],
                'ended_at' => $data['ended_at'] ?? null,
                'duration_ms' => $data['duration_ms'] ?? null,
                'status' => SessionStatus::Uploading,
                'final_provider' => $settings->providerFor($useCase)->value,
            ]);
            foreach ($data['artifacts'] as $artifact) {
                $uploads->createRecording($session, $artifact, $chunkSize);
            }

            return $session;
        });

        return $this->sessionCreatedResponse($session, $stt, 201);
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
        $data = $request->validate(self::artifactRules());
        if ($session->recordings()->where('role', $data['role'])->exists()) {
            throw ValidationException::withMessages(['role' => 'This artifact role already exists for the session.']);
        }
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
