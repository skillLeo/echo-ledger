<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Services\LibraryOrganiser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Library organisation for devices (since v1.1, plan B15): folders, labels, pins, smart views and bulk actions. */
final class OrganiseController extends Controller
{
    public function __construct(private readonly LibraryOrganiser $organiser) {}

    /** PATCH /api/sessions/{id}: title, client folder, pin and labels; only the fields sent change. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'client_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'pinned' => ['sometimes', 'boolean'],
            'labels' => ['sometimes', 'array', 'max:50'],
            'labels.*' => ['nullable', 'string', 'max:200'],
        ]);
        $session = CaptureSession::query()->where('user_id', $this->device($request)->user_id)->find($id);
        if (! $session instanceof CaptureSession) {
            throw new ApiError(404, 'session_not_found', 'Session not found.');
        }
        /** @var array{title?: string|null, client_id?: string|null, pinned?: bool, labels?: list<mixed>} $data */
        $session = $this->organiser->update($session, $data);
        $session->load(['device', 'client', 'transcripts', 'markers:id,capture_session_id,tag,at_ms', 'labels']);

        return response()->json(['data' => (new LibraryItemResource($session))->resolve($request)]);
    }

    /** POST /api/sessions/bulk: one action for up to 200 recordings. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LibraryOrganiser::MAX_BULK],
            'ids.*' => ['string', 'size:26'],
            'action' => ['required', Rule::in(LibraryOrganiser::BULK_ACTIONS)],
            'client_id' => ['nullable', 'string', 'size:26'],
            'label' => ['nullable', 'string', 'max:200'],
        ]);
        $changed = $this->organiser->bulk(
            $this->device($request)->user_id,
            array_values($data['ids']),
            $data['action'],
            $data['client_id'] ?? null,
            $data['label'] ?? null,
        );

        return response()->json(['changed' => $changed]);
    }

    /** POST /api/sessions/export: the finished transcripts of up to 200 recordings in one zip file. */
    public function export(Request $request): Response
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.LibraryOrganiser::MAX_BULK],
            'ids.*' => ['string', 'size:26'],
            'format' => ['required', Rule::in(LibraryOrganiser::EXPORT_FORMATS)],
        ]);
        $file = $this->organiser->exportZip($this->device($request)->user_id, array_values($data['ids']), $data['format']);

        return response($file['body'], 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => ExportController::disposition($file['filename']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Export-Count' => (string) $file['count'],
        ]);
    }

    /** GET /api/library/views: how many recordings each smart view holds, and the labels in use. */
    public function views(Request $request): JsonResponse
    {
        $data = $request->validate(['tz' => ['nullable', 'timezone:all']]);
        $userId = $this->device($request)->user_id;

        return response()->json([
            'views' => $this->organiser->counts($userId, $data['tz'] ?? null),
            'labels' => $this->organiser->labels($userId),
        ]);
    }

    /** POST /api/clients: a new client folder. */
    public function storeClient(Request $request): JsonResponse
    {
        $userId = $this->device($request)->user_id;
        $data = $request->validate(self::clientRules($userId, null, true));
        $client = Client::query()->create(['user_id' => $userId, ...self::clientValues($data)]);

        return response()->json(['data' => ConfigController::clientPayload($client->loadCount('keyterms', 'captureSessions'))], 201);
    }

    /** PATCH /api/clients/{id}: rename, colour, avatar or pin a client folder. */
    public function updateClient(Request $request, string $id): JsonResponse
    {
        $userId = $this->device($request)->user_id;
        $client = Client::query()->where('user_id', $userId)->find($id);
        if (! $client instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }
        $data = $request->validate(self::clientRules($userId, $client->id, false));
        $client->fill(self::clientValues($data))->save();

        return response()->json(['data' => ConfigController::clientPayload($client->loadCount('keyterms', 'captureSessions'))]);
    }

    /** @return array<string, list<mixed>> */
    private static function clientRules(int $userId, ?string $ignore, bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('clients', 'name')->where('user_id', $userId)->ignore($ignore)],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:16'],
            'pinned' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function clientValues(array $data): array
    {
        $values = [];
        if (array_key_exists('name', $data)) {
            $values['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('color', $data)) {
            $values['color'] = $data['color'] === null ? null : strtolower((string) $data['color']);
        }
        if (array_key_exists('avatar', $data)) {
            $values['avatar'] = self::avatar($data['avatar']);
        }
        if (array_key_exists('pinned', $data)) {
            $values['pinned'] = (bool) $data['pinned'];
        }

        return $values;
    }

    /** One emoji or up to two letters; anything else is dropped. */
    public static function avatar(mixed $value): ?string
    {
        $text = is_string($value) ? trim((string) preg_replace('/\p{Cc}+/u', '', $value)) : '';
        if ($text === '') {
            return null;
        }
        $graphemes = preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_slice($graphemes, 0, 2)));
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }
}
