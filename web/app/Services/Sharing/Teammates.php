<?php

declare(strict_types=1);

namespace App\Services\Sharing;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Http\Controllers\Api\ConfigController;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\SentItem;
use App\Models\SessionMarker;
use App\Models\Transcript;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\ChunkedUploadService;
use App\Services\Projects\ProjectTree;
use App\Services\Workspaces\PlanLimits;
use App\Support\UrduText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sending to a teammate (docs/API.md "Sending to a teammate", since v1.5). A recording or a project is sent to
 * accounts on this server by email; each recipient finds it in Shared with me (and its recordings in the `shared`
 * smart folder), can play and read it but not change it, and can save a copy into their own workspace. This is the
 * one place content of one workspace is read for a person of another, and only what was sent to them.
 */
final class Teammates
{
    public const MAX_RECIPIENTS = 20;

    /** @var list<string> encrypted files written by the save in progress */
    private array $written = [];

    public function __construct(private readonly ProjectTree $projects, private readonly ChunkedUploadService $uploads) {}

    /**
     * Sends a recording or a project to every email. Unknown emails are `404 recipient_not_found` and nothing is
     * sent. Sending the same thing to someone again refreshes their item (new note, at the top of the list).
     *
     * @param  list<string>  $emails
     */
    public function send(User $sender, CaptureSession|Client $target, array $emails, ?string $note): int
    {
        $wanted = array_slice(array_values(array_unique(array_map(fn (string $e): string => mb_strtolower(trim($e)), $emails))), 0, self::MAX_RECIPIENTS);
        $people = User::query()->whereIn(DB::raw('LOWER(email)'), $wanted)->get();
        $missing = array_values(array_diff($wanted, $people->map(fn (User $u): string => mb_strtolower($u->email))->all()));
        if ($missing !== [] || $wanted === []) {
            throw new ApiError(404, 'recipient_not_found', 'No account on this server has the email '.implode(', ', $missing ?: $wanted).'. Nothing was sent.', ['emails' => $missing]);
        }
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null;
        $kind = $target instanceof Client ? 'project' : 'session';
        $title = mb_substr($target instanceof Client ? $target->name : $target->displayTitle(), 0, 200);

        DB::transaction(function () use ($sender, $target, $people, $note, $kind, $title): void {
            foreach ($people as $person) {
                $item = SentItem::query()->firstOrNew([
                    'to_user_id' => $person->id,
                    'kind' => $kind,
                    'capture_session_id' => $target instanceof CaptureSession ? $target->id : null,
                    'client_id' => $target instanceof Client ? $target->id : null,
                ]);
                $item->fill(['workspace_id' => $target->workspace_id, 'from_user_id' => $sender->id, 'title' => $title, 'note' => $note, 'sent_at' => now()])->save();
                app(ActivityFeed::class)->sharedWithYou($person, "{$sender->name} sent you {$title}", $note ?? 'Open it in Shared with me.', $target instanceof CaptureSession ? $target->id : null);
            }
        });

        return $people->count();
    }

    /**
     * What was sent to the person and is still there, newest first.
     *
     * @return Collection<int, SentItem>
     */
    public function received(User $person): Collection
    {
        return $this->receivedQuery($person)->with(['sender', 'captureSession', 'client'])->orderByDesc('sent_at')->orderByDesc('id')->get();
    }

    /** @return Builder<SentItem> */
    public function receivedQuery(User $person): Builder
    {
        return SentItem::query()->where('to_user_id', $person->id)
            ->where(fn (Builder $q) => $q->where(fn (Builder $p) => $p->where('kind', 'project')->whereHas('client'))
                ->orWhere(fn (Builder $s) => $s->where('kind', 'session')->whereHas('captureSession')));
    }

    /** One of the person's items, or null (someone else's or an unknown id: the same 404). */
    public function item(User $person, string $id): ?SentItem
    {
        $item = $this->received($person)->firstWhere('id', $id);

        return $item instanceof SentItem ? $item : null;
    }

    /**
     * Every recording the person may read because it was sent to them: sent on its own, or in a sent project or one
     * of its subfolders; not deleted and no longer recording. Workspace scope does not apply (the sender's workspace).
     *
     * @return Builder<CaptureSession>
     */
    public function sharedSessions(User $person): Builder
    {
        $items = SentItem::query()->where('to_user_id', $person->id)->get(['kind', 'capture_session_id', 'client_id']);
        $sessionIds = $items->where('kind', 'session')->pluck('capture_session_id')->filter()->values()->all();
        $projectIds = [];
        foreach (Client::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('id', $items->where('kind', 'project')->pluck('client_id')->filter())->get() as $project) {
            array_push($projectIds, ...$this->projects->withDescendants($project));
        }

        return CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where(fn (Builder $q) => $q->whereIn('id', $sessionIds)->orWhereIn('client_id', $projectIds))
            ->where('status', '!=', SessionStatus::Recording->value);
    }

    public function sharedCount(User $person): int
    {
        return $this->sharedSessions($person)->count();
    }

    /** A recording sent to the person, as they may read it (no project or device of the sender shown), or null. */
    public function readable(User $person, string $id): ?CaptureSession
    {
        $session = $this->sharedSessions($person)->find($id);

        return $session instanceof CaptureSession ? self::readOnly($session) : null;
    }

    /** The sender's project and device are theirs: a received recording is shown in no project, from no device. */
    public static function readOnly(CaptureSession $session): CaptureSession
    {
        $session->setRelation('client', null)->setRelation('device', null);

        return $session;
    }

    /**
     * Saves a copy into the workspace: a recording with its audio (encrypted again under the copy's id), final
     * transcript, views and bookmarks, in the Inbox; a project with its subfolders and their recordings, at the top.
     * `403 storage_limit_reached` when the copy does not fit the plan. The copy is independent of the original.
     *
     * @return array{session_id: string}|array{client_id: string}
     */
    public function save(User $person, int $workspaceId, SentItem $item): array
    {
        $sessions = $item->kind === 'project' && $item->client !== null
            ? CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('client_id', $this->projects->withDescendants($item->client))
                ->where('status', '!=', SessionStatus::Recording->value)->get()
            : CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($item->capture_session_id)->get();
        $bytes = (int) Recording::query()->whereIn('capture_session_id', $sessions->modelKeys())->whereNotNull('stored_path')->sum('stored_size_bytes');

        $this->written = [];
        try {
            $result = DB::transaction(function () use ($person, $workspaceId, $item, $sessions, $bytes): array {
                // One copy at a time in the workspace, with its projects' other changes waiting: two saves at once
                // cannot both pass the plan's storage, and copied folders take names that are free (ProjectTree::place).
                ProjectTree::lock($workspaceId);
                app(PlanLimits::class)->assertStorage($workspaceId, $bytes);
                if ($item->kind === 'project' && $item->client !== null) {
                    $folders = $this->copyTree($item->client, null, $workspaceId, $person);
                    foreach ($sessions as $session) {
                        $this->copySession($session, $workspaceId, $person, $folders[$session->client_id] ?? null);
                    }

                    return ['client_id' => $folders[$item->client->id]];
                }
                $session = $sessions->first();
                if (! $session instanceof CaptureSession) {
                    throw new ApiError(404, 'not_found', 'This recording is no longer available.');
                }

                return ['session_id' => $this->copySession($session, $workspaceId, $person, null)->id];
            });
        } catch (Throwable $e) {
            // The rows were rolled back: the encrypted copies already written must not stay behind.
            Storage::disk('recordings')->delete($this->writtenFiles());
            throw $e;
        }
        // The one id of the copy, a project's or a recording's.
        $item->forceFill(['saved_at' => now(), 'saved_id' => array_values($result)[0]])->save();

        return $result;
    }

    /** @return list<string> the encrypted files the save in progress has written */
    private function writtenFiles(): array
    {
        return $this->written;
    }

    /**
     * Copies a project and its subfolders (names, colours, avatars, languages) into the workspace.
     *
     * @param  array<string, string>  $map  the folders copied so far: a folder is copied once, even from a tree with a loop
     * @return array<string, string> original project id → its copy's id
     */
    private function copyTree(Client $project, ?string $parentId, int $workspaceId, User $person, array $map = []): array
    {
        $copy = Client::query()->withoutGlobalScope(WorkspaceScope::class)->create([
            'workspace_id' => $workspaceId,
            'user_id' => $person->id,
            'parent_id' => $parentId,
            'name' => $this->projects->freeName($workspaceId, $parentId, $project->name),
            'color' => $project->color,
            'avatar' => $project->avatar,
            'language' => $project->language,
        ]);
        $map[$project->id] = $copy->id;
        foreach (Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('parent_id', $project->id)->orderBy('name')->get() as $child) {
            if (! isset($map[$child->id])) {
                $map = $this->copyTree($child, $copy->id, $workspaceId, $person, $map);
            }
        }

        return $map;
    }

    private function copySession(CaptureSession $original, int $workspaceId, User $person, ?string $clientId): CaptureSession
    {
        $project = $original->client_id !== null ? Client::query()->withoutGlobalScope(WorkspaceScope::class)->find($original->client_id) : null;
        $copy = new CaptureSession([
            'workspace_id' => $workspaceId,
            'user_id' => $person->id,
            'device_id' => null,
            'client_id' => $clientId,
            'origin' => CaptureSession::ORIGIN_SAVED,
            'client_ref' => (string) Str::uuid(),
            'source_device' => $original->source_device,
            'source_app' => $original->source_app,
            'title' => $original->displayTitle(),
            'language' => $original->language,
            'started_at' => $original->started_at,
            'ended_at' => $original->ended_at,
            'duration_ms' => $original->duration_ms,
            'pauses' => $original->pauses,
            'language_marks' => $original->language_marks,
            // The names the sender saw, the project's remembered ones included.
            'speaker_names' => array_merge($project->speaker_names ?? [], $original->speaker_names ?? []) ?: null,
            'status' => SessionStatus::Completed,
            'final_provider' => $original->final_provider,
        ]);
        $copy->forceFill(['caller_name' => $original->caller_name, 'caller_number' => $original->caller_number, 'caller_source' => $original->caller_source]);
        $copy->save();

        foreach ($original->recordings()->get() as $recording) {
            if (! $recording->isReady()) {
                continue;
            }
            $plain = $this->uploads->decryptToTemp($recording);
            try {
                $twin = $copy->recordings()->create([
                    'role' => $recording->role, 'mime' => $recording->mime, 'codec' => $recording->codec, 'channels' => $recording->channels,
                    'sample_rate' => $recording->sample_rate, 'duration_ms' => $recording->duration_ms,
                    'declared_size_bytes' => $recording->declared_size_bytes, 'sha256' => $recording->sha256,
                    'chunk_size_bytes' => $recording->chunk_size_bytes, 'total_chunks' => $recording->total_chunks,
                    'received_chunks' => range(0, max(0, $recording->total_chunks - 1)), 'upload_status' => $recording->upload_status,
                ]);
                $this->uploads->storeEncrypted($twin, $plain);
                $this->written[] = (string) $twin->stored_path;
            } finally {
                @unlink($plain);
            }
        }

        $final = $original->transcripts()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)->first();
        if ($final instanceof Transcript) {
            $meta = $final->meta ?? [];
            // Suggested fixes came from the sender's project vocabulary; the views are the copy's to keep.
            unset($meta['suggestions']);
            $transcript = new Transcript([
                'capture_session_id' => $copy->id, 'kind' => TranscriptKind::Final, 'provider' => $final->provider, 'status' => TranscriptStatus::Completed,
                'language' => $final->language, 'text' => $final->text, 'segments' => $final->segments, 'meta' => $meta === [] ? null : $meta,
                'completed_at' => $final->completed_at ?? now(),
            ]);
            $transcript->forceFill(['search_text' => $final->text === null ? null : UrduText::fold((string) $final->text)]);
            // No "Transcript ready" for a copy (the model events would post one), so it is written without them.
            Transcript::withoutEvents(fn (): bool => $transcript->save());
        }
        foreach ($original->markers()->get() as $marker) {
            SessionMarker::query()->create(['capture_session_id' => $copy->id, 'at_ms' => $marker->at_ms, 'tag' => $marker->tag, 'note' => $marker->note, 'source' => $marker->source]);
        }

        return $copy;
    }

    /**
     * One item as GET /api/shared-with-me lists it.
     *
     * @param  callable(CaptureSession): array<string, mixed>  $sessionPayload
     * @return array<string, mixed>
     */
    public function payload(SentItem $item, callable $sessionPayload): array
    {
        $client = $item->kind === 'project' ? $item->client : null;
        $session = $item->kind === 'session' ? $item->captureSession : null;

        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'from' => ['name' => $item->sender->name, 'email' => $item->sender->email],
            'note' => $item->note,
            'title' => $item->title,
            'sent_at' => $item->sent_at->toAtomString(),
            'saved_at' => $item->saved_at?->toAtomString(),
            'session' => $session !== null ? $sessionPayload(self::readOnly($session)) : null,
            'client' => $client !== null ? $this->projectPayload($client) : null,
        ];
    }

    /**
     * A sent project as the recipient sees it: its own counts, none of the sender's tree around it.
     *
     * @return array<string, mixed>
     */
    private function projectPayload(Client $project): array
    {
        $ids = $this->projects->withDescendants($project);
        $project->loadCount(['keyterms', 'captureSessions']);

        return [
            ...ConfigController::clientPayload($project),
            'parent_id' => null,
            'path' => [],
            'children_count' => Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('parent_id', $project->id)->count(),
            'nested_session_count' => CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('client_id', $ids)->count(),
        ];
    }

    /** Takes an item off the person's list; the sender's recording or project stays. */
    public function remove(SentItem $item): void
    {
        $item->delete();
    }
}
