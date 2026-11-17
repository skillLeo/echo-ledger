<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Assist;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\AssistPrep;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Device;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Assist\AssistDefaults;
use App\Services\Assist\AssistRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Live Assist preps (docs/API_ASSIST.md "Preps"): the device's own person's, in the device's workspace; another
 * workspace's or person's id answers 404. Reads need `library:read`, writes `sessions:write`.
 */
final class AssistPrepController extends Controller
{
    /** GET /api/assist/preps?status= */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'nullable', Rule::in(AssistPrep::STATUSES)]]);
        $preps = $this->query($request)->with('documents')->orderByDesc('scheduled_at')->orderByDesc('created_at')->limit(200)->get();
        $status = $data['status'] ?? null;

        return response()->json(['data' => $preps
            ->filter(fn (AssistPrep $p): bool => $status === null || $p->currentStatus() === $status)
            ->map(fn (AssistPrep $p): array => self::payload($p))->values()->all()]);
    }

    /** POST /api/assist/preps */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $device = $this->device($request);
        $type = (string) $data['meeting_type'];
        // What the request leaves out comes from the person's Live Assist settings for this meeting type (L8).
        $defaults = AssistDefaults::for($device->user, $type);
        $prep = new AssistPrep([
            'workspace_id' => $device->workspace_id,
            'user_id' => $device->user_id,
            'meeting_type' => $type,
            'hint_level' => $data['hint_level'] ?? $defaults['hint_level'],
            'mode' => $data['mode'] ?? $defaults['mode'],
            'language_help' => $data['language_help'] ?? $defaults['language_help'],
            'status' => $data['status'] ?? (isset($data['scheduled_at']) ? 'scheduled' : 'draft'),
        ]);
        $this->fill($request, $prep, $data);
        $prep->save();

        return response()->json(self::payload($prep->load('documents')), 201);
    }

    /** GET /api/assist/preps/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(self::payload($this->find($request, $id)->load('documents'), withText: true));
    }

    /** PATCH /api/assist/preps/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $prep = $this->find($request, $id);
        $data = $request->validate($this->rules(false));
        foreach (['meeting_type', 'hint_level', 'mode', 'language_help', 'status'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $prep->setAttribute($field, $data[$field]);
            }
        }
        $this->fill($request, $prep, $data);
        $prep->save();

        return response()->json(self::payload($prep->load('documents')));
    }

    /** DELETE /api/assist/preps/{id} */
    public function destroy(Request $request, string $id): Response
    {
        AssistRecords::erasePrep($this->find($request, $id));

        return response()->noContent();
    }

    /** POST /api/assist/preps/{id}/documents {"file_ids": [...]} */
    public function attach(Request $request, string $id): JsonResponse
    {
        $prep = $this->find($request, $id);
        $data = $request->validate(['file_ids' => ['required', 'array', 'min:1', 'max:20'], 'file_ids.*' => ['string', 'max:26']]);
        $device = $this->device($request);
        // Files of the person's own projects in this workspace (projects are private, v1.5).
        $files = ClientFile::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $device->workspace_id)
            ->whereIn('client_id', Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $device->workspace_id)
                ->where('user_id', $device->user_id)->select('id'))
            ->whereIn('id', array_unique($data['file_ids']))->get();
        if ($files->count() !== count(array_unique($data['file_ids']))) {
            throw new NotFoundHttpException;
        }
        foreach ($files as $file) {
            // About four characters a token, which is what the relay's budget expects of a document.
            $prep->documents()->syncWithoutDetaching([$file->id => ['tokens' => (int) ceil(mb_strlen((string) $file->text) / 4)]]);
        }

        return response()->json(self::payload($prep->load('documents')));
    }

    /** DELETE /api/assist/preps/{id}/documents/{fileId} */
    public function detach(Request $request, string $id, string $fileId): Response
    {
        $prep = $this->find($request, $id);
        if ($prep->documents()->detach($fileId) === 0) {
            throw new NotFoundHttpException;
        }

        return response()->noContent();
    }

    /** POST /api/assist/preps/{id}/arm → `{"armed_until"}`: it waits for its meeting until 10 minutes after the time set. */
    public function arm(Request $request, string $id): JsonResponse
    {
        $prep = $this->find($request, $id);
        if (in_array($prep->currentStatus(), ['done', 'cancelled'], true)) {
            throw new ApiError(422, 'validation_failed', 'This prep is closed.', ['errors' => ['status' => ['This prep is closed.']]]);
        }
        $from = $prep->scheduled_at !== null && $prep->scheduled_at->isFuture() ? $prep->scheduled_at : now();
        $prep->forceFill(['status' => 'armed', 'armed_until' => $from->copy()->addMinutes(AssistPrep::ARMED_MINUTES)])->save();

        return response()->json(['armed_until' => $prep->armed_until?->toAtomString()]);
    }

    /** @return array<string, mixed> a prep as the apps read it */
    public static function payload(AssistPrep $prep, bool $withText = false): array
    {
        return [
            'id' => $prep->id,
            'meeting_type' => $prep->meeting_type,
            'title' => $prep->title,
            'counterpart' => $prep->counterpart,
            'company' => $prep->company,
            'project_id' => $prep->client_id,
            'goals' => $prep->goals,
            'done' => $prep->done,
            'not_done' => $prep->not_done,
            'to_discuss' => $prep->to_discuss,
            'instructions' => $prep->instructions,
            'hint_level' => $prep->hint_level,
            'mode' => $prep->mode,
            'language_help' => $prep->language_help,
            'scheduled_at' => $prep->scheduled_at?->toAtomString(),
            'armed_until' => $prep->armed_until?->toAtomString(),
            'status' => $prep->currentStatus(),
            // Since 1.9.0-m the extracted text too, on the single read only (the app sends it to the relay), never in lists.
            'documents' => $prep->documents->map(fn (ClientFile $f): array => [
                'file_id' => $f->id, 'name' => $f->name, 'tokens' => (int) $f->getRelationValue('pivot')?->getAttribute('tokens'),
                ...($withText ? ['text' => (string) $f->text] : []),
            ])->values()->all(),
            'created_at' => $prep->created_at?->toAtomString(),
            'updated_at' => $prep->updated_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $text = ['sometimes', 'nullable', 'string', 'max:'.AssistPrep::MAX_TEXT];

        return [
            'meeting_type' => [$creating ? 'required' : 'sometimes', Rule::in(AssistPrep::MEETING_TYPES)],
            ...array_fill_keys(AssistPrep::TEXT_FIELDS, $text),
            'project_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'hint_level' => ['sometimes', Rule::in(AssistPrep::HINT_LEVELS)],
            'mode' => ['sometimes', Rule::in(AssistPrep::MODES)],
            'language_help' => ['sometimes', Rule::in(AssistPrep::LANGUAGE_HELP)],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(AssistPrep::STATUSES)],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function fill(Request $request, AssistPrep $prep, array $data): void
    {
        foreach (AssistPrep::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $value = is_string($data[$field]) ? trim($data[$field]) : null;
                $prep->setAttribute($field, $value === '' ? null : $value);
            }
        }
        if (array_key_exists('scheduled_at', $data)) {
            $prep->scheduled_at = $data['scheduled_at'] !== null ? now()->parse((string) $data['scheduled_at'])->utc() : null;
        }
        if (array_key_exists('project_id', $data)) {
            $prep->client_id = $data['project_id'] === null ? null : $this->project($request, (string) $data['project_id']);
        }
    }

    /** A project of the device's workspace, or 404. */
    private function project(Request $request, string $id): string
    {
        // The person's own project in this workspace: a teammate's is as unknown as any other (projects are private, v1.5).
        $device = $this->device($request);
        $exists = Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $device->workspace_id)
            ->where('user_id', $device->user_id)->whereKey($id)->exists();
        if (! $exists) {
            throw new NotFoundHttpException;
        }

        return $id;
    }

    /** @return Builder<AssistPrep> */
    private function query(Request $request): Builder
    {
        $device = $this->device($request);

        return AssistPrep::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id);
    }

    private function find(Request $request, string $id): AssistPrep
    {
        $prep = $this->query($request)->find($id);
        if (! $prep instanceof AssistPrep) {
            throw new NotFoundHttpException;
        }

        return $prep;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
