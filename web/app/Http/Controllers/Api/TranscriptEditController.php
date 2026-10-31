<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\TextReplacements;
use App\Services\TranscriptEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** docs/API.md "Editing transcripts" (since v1.1): edits, find and replace, restore, revisions and speaker names. */
final class TranscriptEditController extends Controller
{
    public function __construct(private readonly TranscriptEditor $editor) {}

    /** PATCH /api/transcripts/{id}: new text for some segments (timings stay). */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'edits' => ['required', 'array', 'min:1', 'max:2000'],
            'edits.*' => ['array'],
            'edits.*.index' => ['required', 'integer', 'min:0'],
            'edits.*.text' => ['required', 'string', 'max:'.TranscriptEditor::MAX_SEGMENT_CHARS],
        ]);
        $edits = [];
        foreach ($data['edits'] as $edit) {
            $edits[(int) $edit['index']] = (string) $edit['text'];
        }
        $transcript = $this->transcript($request, $id);
        $before = [];
        foreach (array_keys($edits) as $index) {
            $before[$index] = (string) (($transcript->segments ?? [])[$index]['text'] ?? '');
        }
        $saved = $this->editor->edit($transcript, $edits, $this->author($request));
        // Since v1.4: for a client's recording, the words changed are offered as rules for that client.
        $client = $saved->captureSession->client;
        $learn = $client === null ? [] : array_map(
            fn (array $rule): array => $rule + ['client_id' => $client->id, 'client_name' => $client->name],
            TranscriptEditor::learnable($before, $edits, TextReplacements::rules($client->replacements)),
        );

        // The transcript stays flat at the top level (as before v1.4), with `learn` beside its fields.
        return response()->json([...(new TranscriptResource($saved))->resolve($request), 'learn' => $learn]);
    }

    /** POST /api/transcripts/{id}/suggestions/{suggestion} — accept (the words change) or dismiss a suggested fix. */
    public function suggestion(Request $request, string $id, string $suggestion): TranscriptResource
    {
        $data = $request->validate(['accept' => ['required', 'boolean']]);

        return new TranscriptResource(app(ClientWorkspace::class)->decideFix(
            $this->transcript($request, $id),
            (int) $suggestion,
            (bool) $data['accept'],
            $this->author($request),
        ));
    }

    /** POST /api/transcripts/{id}/replace */
    public function replace(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'find' => ['required', 'string', 'max:200'],
            'replace' => ['present', 'nullable', 'string', 'max:200'],
            'match_case' => ['sometimes', 'boolean'],
            'whole_word' => ['sometimes', 'boolean'],
        ]);
        [$transcript, $count] = $this->editor->replace(
            $this->transcript($request, $id),
            (string) $data['find'],
            (string) ($data['replace'] ?? ''),
            (bool) ($data['match_case'] ?? false),
            (bool) ($data['whole_word'] ?? false),
            $this->author($request),
        );

        return response()->json(['replaced' => $count, 'transcript' => (new TranscriptResource($transcript))->resolve($request)]);
    }

    /** POST /api/transcripts/{id}/restore: back to the provider's original text. */
    public function restore(Request $request, string $id): TranscriptResource
    {
        return new TranscriptResource($this->editor->restoreOriginal($this->transcript($request, $id), $this->author($request)));
    }

    /** GET /api/transcripts/{id}/revisions */
    public function revisions(Request $request, string $id): JsonResponse
    {
        $revisions = $this->editor->revisions($this->transcript($request, $id));

        return response()->json(['data' => $revisions->map(fn (TranscriptRevision $r): array => $r->toApi())->values()]);
    }

    /** PUT /api/sessions/{id}/speakers: rename speakers, optionally remembered for the session's client. */
    public function speakers(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'names' => ['required', 'array', 'min:1', 'max:20'],
            'names.*' => ['present', 'nullable', 'string', 'max:'.TranscriptEditor::MAX_SPEAKER_CHARS],
            'remember_for_client' => ['sometimes', 'boolean'],
        ]);
        foreach (array_keys($data['names']) as $label) {
            if (! is_string($label) || mb_strlen($label) > TranscriptEditor::MAX_SPEAKER_CHARS) {
                throw new ApiError(422, 'validation_failed', 'Speaker labels are the names shown in the transcript, at most 60 characters.');
            }
        }
        $session = $this->session($request, $id);
        $names = $this->editor->renameSpeakers($session, $data['names'], (bool) ($data['remember_for_client'] ?? false));

        return response()->json(['speaker_names' => (object) $names]);
    }

    private function transcript(Request $request, string $id): Transcript
    {
        /** @var Device $device */
        $device = $request->user();
        $transcript = Transcript::query()
            ->whereHas('captureSession', fn ($q) => $q->where('workspace_id', $device->workspace_id)->whereNull('deleted_at'))
            ->find($id);
        if (! $transcript instanceof Transcript) {
            throw new ApiError(404, 'transcript_not_found', 'Transcript not found.');
        }

        return $transcript;
    }

    private function session(Request $request, string $id): CaptureSession
    {
        /** @var Device $device */
        $device = $request->user();
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $session;
    }

    private function author(Request $request): ?string
    {
        $device = $request->user();

        return $device instanceof Device ? mb_substr($device->name, 0, 120) : null;
    }
}
