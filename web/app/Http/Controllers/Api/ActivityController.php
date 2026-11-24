<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Notifications\DatabaseNotification;

/** The activity centre for device apps (since v1.1, plan B18). */
final class ActivityController extends Controller
{
    /** The Activity groups (contract 2.0.0-r, BE-02): only upload failures are grouped. */
    public const GROUPS = ['upload_failed'];

    /** GET /api/activity?limit=&group= — newest first, with the unread count; since 2.0.0-r one group's items only. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'group' => ['nullable', 'string', Rule::in(self::GROUPS)]]);
        $user = $this->owner($request);
        $query = $user->notifications()->latest();
        if (($data['group'] ?? null) !== null) {
            $query->where('dedupe_key', 'like', $data['group'].':%');
        }
        $items = $query->limit((int) ($data['limit'] ?? 30))->get();

        return response()->json([
            'data' => $items->map(fn (DatabaseNotification $n): array => ActivityFeed::payload($n))->values(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * GET /api/activity/groups (contract 2.0.0-r, BE-02): each group that holds an unresolved item, with how many, how
     * many unread, their recordings and the newest one's time ("3 recordings didn't finish uploading · Retry all").
     */
    public function groups(Request $request): JsonResponse
    {
        $user = $this->owner($request);
        $groups = [];
        foreach (self::GROUPS as $group) {
            $items = $user->notifications()->where('dedupe_key', 'like', $group.':%')->whereNull('resolved_at')->latest()->get();
            if ($items->isNotEmpty()) {
                $groups[] = ['group' => $group, 'count' => $items->count(), 'unread' => $items->whereNull('read_at')->count(),
                    'session_ids' => $items->map(fn (DatabaseNotification $n): ?string => ActivityFeed::payload($n)['session_id'])->filter()->values()->all(),
                    'at' => $items->first()->created_at?->toAtomString()];
            }
        }

        return response()->json(['data' => $groups]);
    }

    /** POST /api/activity/read — the given ids, or everything when no ids are sent. */
    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['sometimes', 'array', 'max:100'], 'ids.*' => ['string', 'uuid']]);
        $user = $this->owner($request);
        $query = $user->unreadNotifications();
        if (array_key_exists('ids', $data)) {
            $query->whereIn('id', $data['ids']);
        }
        $query->update(['read_at' => now()]);

        return response()->json(['unread_count' => $user->unreadNotifications()->count()]);
    }

    private function owner(Request $request): User
    {
        /** @var Device $device */
        $device = $request->user();

        return $device->user;
    }
}
