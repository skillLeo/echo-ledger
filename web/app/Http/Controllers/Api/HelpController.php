<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use App\Models\Device;
use App\Services\Help\Guides;
use App\Services\Help\SupportMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * In-app help (v1.8 P6.4, docs/API.md 2.0.0-d): the user guide's sections and their search, What's new from the
 * releases' notes, and Contact support.
 */
final class HelpController extends Controller
{
    /** The platforms What's new answers for: the phone, Windows and the Mac (both builds). */
    public const PLATFORMS = ['android' => [AppRelease::ANDROID], 'windows' => [AppRelease::WINDOWS], 'mac' => [AppRelease::MAC_ARM, AppRelease::MAC_INTEL]];

    /** GET /api/help/guides?q= — `config:read`. */
    public function guides(Request $request, Guides $guides): JsonResponse
    {
        $data = $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:200']]);

        return response()->json(['data' => $guides->search($data['q'] ?? null)]);
    }

    /** GET /api/help/guides/{id} — `config:read`. */
    public function guide(string $id, Guides $guides): JsonResponse
    {
        $guide = $guides->find($id);
        if ($guide === null) {
            throw new NotFoundHttpException;
        }

        return response()->json($guide);
    }

    /** GET /api/help/whats-new?platform= — `config:read`: the platform's last 10 releases with notes, newest first. */
    public function whatsNew(Request $request): JsonResponse
    {
        $data = $request->validate(['platform' => ['sometimes', 'nullable', Rule::in(array_keys(self::PLATFORMS))]]);

        return response()->json(['data' => self::whatsNewFor($data['platform'] ?? null)]);
    }

    /**
     * The releases that have notes, newest first (one line per version of the Mac's two builds).
     *
     * @return list<array{version: string, platform: string, released_at: string|null, notes: string}>
     */
    public static function whatsNewFor(?string $platform, int $limit = 10): array
    {
        $names = [];
        foreach (self::PLATFORMS as $name => $keys) {
            foreach ($keys as $key) {
                $names[$key] = $name;
            }
        }

        return AppRelease::query()->whereNotNull('notes')->where('notes', '!=', '')
            ->when($platform !== null, fn ($q) => $q->whereIn('platform', self::PLATFORMS[$platform]))
            ->latest('created_at')->limit($limit * 2)->get()
            ->map(fn (AppRelease $r): array => ['version' => $r->version, 'platform' => $names[$r->platform] ?? $r->platform,
                'released_at' => $r->created_at?->toAtomString(), 'notes' => (string) $r->notes])
            ->unique(fn (array $r): string => $r['platform'].'|'.$r['version'])->take($limit)->values()->all();
    }

    /** POST /api/support/messages — `sessions:write`, 5 an hour per person. */
    public function support(Request $request, SupportMessages $support): Response
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:5000'],
            'include_device_report' => ['sometimes', 'boolean'],
        ]);
        /** @var Device $device */
        $device = $request->user();
        $support->send($device->user, (string) $data['subject'], (string) $data['message'], (bool) ($data['include_device_report'] ?? false), $device);

        return response()->noContent(202);
    }
}
