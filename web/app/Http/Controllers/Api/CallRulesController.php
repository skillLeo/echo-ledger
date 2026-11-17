<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\CallRules\AudioRetention;
use App\Services\CallRules\BatchTranscribe;
use App\Services\CallRules\CallRules;
use App\Services\CallRules\SessionChoice;
use App\Services\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Call rules, the after-call choice, Transcribe many and the person's audio retention (contract 2.1.0-a, docs/API.md
 * "Call rules and audio clean-up").
 */
final class CallRulesController extends Controller
{
    public function __construct(private readonly CallRules $rules) {}

    /** GET /api/call-rules */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->rules->document($this->device($request)->user));
    }

    /** PUT /api/call-rules/default */
    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string']]);
        $user = $this->device($request)->user;
        $this->rules->setDefault($user, $data['action']);

        return response()->json($this->rules->document($user));
    }

    /** PUT /api/call-rules/short-calls */
    public function setShortCalls(Request $request): JsonResponse
    {
        $data = $request->validate(['keep' => ['required', 'boolean']]);
        $user = $this->device($request)->user;
        $this->rules->setShortCalls($user, (bool) $data['keep']);

        return response()->json($this->rules->document($user));
    }

    /** PUT and DELETE /api/call-rules/groups/{group} */
    public function group(Request $request, string $group): JsonResponse
    {
        abort_unless(in_array($group, CallRules::GROUPS, true), 404);

        return $this->named($request, 'group', $group);
    }

    /** PUT and DELETE /api/call-rules/sources/{source} */
    public function source(Request $request, string $source): JsonResponse
    {
        abort_unless(in_array($source, CallRules::SOURCES, true), 404);

        return $this->named($request, 'source', $source);
    }

    /** PUT /api/call-rules/contacts */
    public function putContact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'min:3', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
            'action' => ['required', 'string'],
        ]);
        $this->assertKey($data['key']);
        $user = $this->device($request)->user;
        $this->rules->setRule($user, 'contact', $data['key'], $data['action'], $data['name'] ?? null);

        return response()->json($this->rules->document($user));
    }

    /** DELETE /api/call-rules/contacts?key= */
    public function deleteContact(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'min:3', 'max:120']]);
        $this->assertKey($data['key']);
        $user = $this->device($request)->user;
        $this->rules->setRule($user, 'contact', $data['key'], null);

        return response()->json($this->rules->document($user));
    }

    /** GET /api/call-rules/resolve */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:40'],
            'key' => ['nullable', 'string', 'max:120'],
            'group' => ['nullable', Rule::in(CallRules::GROUPS)],
        ]);

        return response()->json($this->rules->resolve($this->device($request)->user, $data['source'] ?? null, $data['key'] ?? null, $data['group'] ?? null));
    }

    /** POST /api/sessions/{id}/choice */
    public function choice(Request $request, string $id, SessionChoice $choices): JsonResponse
    {
        $data = $request->validate([
            'choice' => ['required', 'string'],
            'choice_by' => ['nullable', Rule::in(['user', 'timeout'])],
        ]);
        $device = $this->device($request);
        $session = $this->ownSession($device, $id);
        if (! in_array($data['choice'], ['transcribe', 'keep_audio', 'delete'], true)) {
            throw new ApiError(422, 'invalid_choice', 'That is not one of the choices for a recording.');
        }
        if ($data['choice'] === 'delete') {
            app(Workspaces::class)->assertCanDeleteSession($device->user, $session);
            $session->delete();

            return response()->json(['data' => (new LibraryItemResource(CaptureSession::withTrashed()->findOrFail($session->id)))->resolve($request)]);
        }
        $session = $choices->choose($session, $data['choice'], $data['choice_by'] ?? 'user');

        return response()->json(['data' => (new LibraryItemResource($session))->resolve($request)]);
    }

    /** POST /api/sessions/transcribe/estimate */
    public function estimate(Request $request, BatchTranscribe $batch): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['string', 'size:26']]);

        return response()->json($batch->estimate($this->device($request), $data['ids']));
    }

    /** POST /api/sessions/transcribe */
    public function transcribeMany(Request $request, BatchTranscribe $batch): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['string', 'size:26'],
            'max_minutes' => ['required', 'numeric', 'min:0'],
        ]);

        return response()->json(['started' => $batch->start($this->device($request), $data['ids'], (float) $data['max_minutes'])]);
    }

    /** GET /api/account/privacy */
    public function privacy(Request $request, AudioRetention $retention, BatchTranscribe $batch): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'audio_retention' => $retention->describe($device->user, (int) $device->workspace_id),
            'storage' => $batch->storage($device),
        ]);
    }

    /** PUT /api/account/privacy */
    public function putPrivacy(Request $request, AudioRetention $retention, BatchTranscribe $batch): JsonResponse
    {
        $data = $request->validate(['audio_retention_choice' => ['required']]);
        $choice = $data['audio_retention_choice'];
        if (! is_int($choice) && ! in_array($choice, ['plan', 'never'], true)) {
            throw new ApiError(422, 'invalid_choice', 'That is not one of the choices.');
        }
        $device = $this->device($request);
        $retention->choose($device->user, (int) $device->workspace_id, $choice);

        return $this->privacy($request, $retention, $batch);
    }

    private function named(Request $request, string $kind, string $key): JsonResponse
    {
        $user = $this->device($request)->user;
        if ($request->isMethod('DELETE')) {
            $this->rules->setRule($user, $kind, $key, null);
        } else {
            $data = $request->validate(['action' => ['required', 'string']]);
            $this->rules->setRule($user, $kind, $key, $data['action']);
        }

        return response()->json($this->rules->document($user));
    }

    private function assertKey(string $key): void
    {
        if (! CallRules::validKey($key)) {
            throw new ApiError(422, 'invalid_key', 'A contact is named tel:, wa: or app: and its number or id.');
        }
    }

    private function ownSession(Device $device, string $id): CaptureSession
    {
        $session = CaptureSession::query()->where('workspace_id', $device->workspace_id)->where('user_id', $device->user_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Recording not found.');
        }

        return $session;
    }

    private function device(Request $request): Device
    {
        $device = $request->user();
        abort_unless($device instanceof Device, 401);

        return $device;
    }
}
