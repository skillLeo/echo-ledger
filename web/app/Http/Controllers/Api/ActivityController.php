<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\ActivityFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The activity centre for device apps (since v1.1, plan B18). */
final class ActivityController extends Controller
{
    /** GET /api/activity?limit= — newest first, with the unread count. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $user = $this->owner($request);
        $items = $user->notifications()->latest()->limit((int) ($data['limit'] ?? 30))->get();

        return response()->json([
            'data' => $items->map(fn (DatabaseNotification $n): array => ActivityFeed::payload($n))->values(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
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
