<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Jobs\MakeCallNotesJob;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\Device;
use App\Services\ClientAi\ClientWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Client workspaces (v1.4, P4; docs/API.md "Client workspaces"): the brief, memory, call notes, files, Ask and the
 * "Copy for AI" bundle of one client, for every app.
 */
final class ClientWorkspaceController extends Controller
{
    public function __construct(private readonly ClientWorkspace $workspace) {}

    /** GET /api/clients/{id}/workspace */
    public function show(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $brief = ClientBrief::query()->where('client_id', $client->id)->first();
        $notes = CallNote::query()->where('client_id', $client->id)->with('captureSession')->latest()->limit(10)->get();

        return response()->json(['data' => [
            'client' => ['id' => $client->id, 'name' => $client->name, 'ai_notes' => $client->ai_notes],
            'ai' => ['configured' => $this->workspace->configured(), 'client_spent_usd' => $this->workspace->clientSpend($client)] + $this->workspace->budget($client->workspace_id),
            'brief' => $brief?->toApi() ?? ['sections' => ClientBrief::emptySections(), 'edited' => false, 'status' => 'ready', 'error' => null, 'generated_at' => null, 'updated_at' => null],
            'memory' => ClientMemoryItem::query()->where('client_id', $client->id)->orderByDesc('pinned')->orderByDesc('happened_on')->orderByDesc('created_at')
                ->limit(300)->get()->map(fn (ClientMemoryItem $m): array => $m->toApi())->all(),
            'notes' => $notes->map(fn (CallNote $n): array => self::noteWithSession($n))->all(),
            'files' => ClientFile::query()->where('client_id', $client->id)->latest()->get()->map(fn (ClientFile $f): array => $f->toApi())->all(),
        ]]);
    }

    /** PUT /api/clients/{id}/brief — a person's edit; the brief is then never rewritten by itself. */
    public function updateBrief(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $data = $request->validate([
            'sections' => ['required', 'array'],
            'sections.goal' => ['sometimes', 'nullable', 'string', 'max:300'],
            'sections.*' => ['nullable'],
        ]);
        $brief = ClientBrief::query()->firstOrNew(['client_id' => $client->id]);
        $brief->fill(['workspace_id' => $client->workspace_id, 'sections' => ClientBrief::clean($data['sections']), 'edited' => true, 'status' => 'ready', 'error' => null])->save();

        return response()->json(['data' => $brief->toApi()]);
    }

    /** POST /api/clients/{id}/brief/refresh — make it again from the files (or calls), replacing an edited one. */
    public function refreshBrief(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $this->workspace->assertCanSpend($client->workspace_id);
        $brief = ClientBrief::query()->firstOrNew(['client_id' => $client->id]);
        $brief->fill(['workspace_id' => $client->workspace_id, 'sections' => $brief->sections ?? ClientBrief::emptySections(), 'status' => 'working', 'error' => null])->save();
        RefreshClientBriefJob::dispatch($client->id, true);

        return response()->json(['data' => $brief->refresh()->toApi()], 202);
    }

    /** POST /api/clients/{id}/memory — a line a person adds. */
    public function storeMemory(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $data = $request->validate([
            'kind' => ['required', Rule::in(ClientMemoryItem::KINDS)],
            'text' => ['required', 'string', 'max:500'],
            'happened_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $item = ClientMemoryItem::query()->create([
            'workspace_id' => $client->workspace_id,
            'client_id' => $client->id,
            'kind' => $data['kind'],
            'text' => trim($data['text']),
            'happened_on' => $data['happened_on'] ?? now()->toDateString(),
            'pinned' => false,
            'source_type' => 'user',
        ]);

        return response()->json(['data' => $item->toApi()], 201);
    }

    /** PATCH /api/clients/{id}/memory/{item} — edit or pin a line. */
    public function updateMemory(Request $request, string $id, string $item): JsonResponse
    {
        $line = $this->memoryItem($request, $id, $item);
        $data = $request->validate(['text' => ['sometimes', 'string', 'max:500'], 'pinned' => ['sometimes', 'boolean']]);
        if (isset($data['text'])) {
            $line->text = trim($data['text']);
        }
        if (array_key_exists('pinned', $data)) {
            $line->pinned = (bool) $data['pinned'];
        }
        $line->save();

        return response()->json(['data' => $line->toApi()]);
    }

    /** DELETE /api/clients/{id}/memory/{item} */
    public function destroyMemory(Request $request, string $id, string $item): Response
    {
        $this->memoryItem($request, $id, $item)->delete();

        return response()->noContent();
    }

    /** POST /api/clients/{id}/files — a PDF, Word, text or Markdown file (multipart `file`), or pasted `text`. */
    public function storeFile(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $data = $request->validate([
            'file' => ['required_without:text', 'file', 'max:'.(ClientWorkspace::MAX_FILE_BYTES / 1024)],
            'text' => ['required_without:file', 'string', 'max:100000'],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $userId = $this->device($request)->user_id;
        $file = isset($data['file'])
            ? $this->workspace->addFile($client, $request->file('file'), $userId)
            : $this->workspace->addInstructions($client, $data['text'], $data['name'] ?? null, $userId);

        return response()->json(['data' => $file->toApi()], 201);
    }

    /** DELETE /api/clients/{id}/files/{file} */
    public function destroyFile(Request $request, string $id, string $file): Response
    {
        $client = $this->client($request, $id);
        $found = ClientFile::query()->where('client_id', $client->id)->find($file);
        if (! $found instanceof ClientFile) {
            throw new ApiError(404, 'file_not_found', 'File not found.');
        }
        $this->workspace->deleteFile($found);

        return response()->noContent();
    }

    /** POST /api/clients/{id}/ask — a short answer with citations. */
    public function ask(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
            'language' => ['sometimes', Rule::in(['en', 'roman_urdu'])],
        ]);

        return response()->json(['data' => $this->workspace->ask($client, trim($data['question']), $data['language'] ?? 'en')]);
    }

    /** GET /api/clients/{id}/bundle?notes=5 — "Copy for AI". */
    public function bundle(Request $request, string $id): JsonResponse
    {
        $client = $this->client($request, $id);
        $notes = $request->validate(['notes' => ['sometimes', 'integer', 'min:0', 'max:20']])['notes'] ?? 5;

        return response()->json(['data' => ['text' => $this->workspace->bundle($client, (int) $notes)]]);
    }

    /** GET /api/sessions/{id}/notes */
    public function notes(Request $request, string $id): JsonResponse
    {
        $session = $this->session($request, $id);
        $note = CallNote::query()->where('capture_session_id', $session->id)->first();
        if (! $note instanceof CallNote) {
            throw new ApiError(404, 'notes_not_found', 'This recording has no notes yet.');
        }

        return response()->json(['data' => self::noteWithSession($note->setRelation('captureSession', $session))]);
    }

    /** POST /api/sessions/{id}/notes — write the notes (again), optionally in Roman Urdu; done on the queue. */
    public function makeNotes(Request $request, string $id): JsonResponse
    {
        $session = $this->session($request, $id);
        if ($session->client_id === null) {
            throw new ApiError(409, 'no_client', 'File this recording under a client first.');
        }
        $data = $request->validate(['language' => ['sometimes', Rule::in(['en', 'roman_urdu'])]]);
        $this->workspace->assertCanSpend($session->workspace_id);
        $note = CallNote::query()->updateOrCreate(['capture_session_id' => $session->id], [
            'workspace_id' => $session->workspace_id,
            'client_id' => $session->client_id,
            'status' => 'pending',
            'error' => null,
            'language' => $data['language'] ?? 'en',
        ]);
        MakeCallNotesJob::dispatch($session->id);

        return response()->json(['data' => self::noteWithSession($note->setRelation('captureSession', $session))], 202);
    }

    /** @return array<string, mixed> */
    private static function noteWithSession(CallNote $note): array
    {
        $session = $note->captureSession;

        return $note->toApi() + ['session' => $session === null ? null : [
            'id' => $session->id,
            'title' => $session->title,
            'started_at' => $session->started_at->toIso8601ZuluString(),
            'duration_ms' => $session->duration_ms,
        ]];
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }

    private function client(Request $request, string $id): Client
    {
        $client = Client::query()->where('workspace_id', $this->device($request)->workspace_id)->find($id);
        if (! $client instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }

        return $client;
    }

    private function session(Request $request, string $id): CaptureSession
    {
        $session = CaptureSession::query()->where('workspace_id', $this->device($request)->workspace_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }

        return $session;
    }

    private function memoryItem(Request $request, string $id, string $item): ClientMemoryItem
    {
        $client = $this->client($request, $id);
        $line = ClientMemoryItem::query()->where('client_id', $client->id)->find($item);
        if (! $line instanceof ClientMemoryItem) {
            throw new ApiError(404, 'memory_not_found', 'That line is not in this client\'s memory.');
        }

        return $line;
    }
}
