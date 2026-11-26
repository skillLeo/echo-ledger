<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\Accounts\Avatars;
use App\Support\Api\FieldErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

/** Profile pictures (since 2.0.0-l, docs/API.md "Account and security from the apps" › 3). */
final class AvatarController extends Controller
{
    public function __construct(private readonly Avatars $avatars) {}

    /**
     * GET /api/avatars/{user}/{version}?size=128|512 — the signed, versioned `avatar_url`. An expired or wrong signature
     * is `403` and a version replaced or removed `404`, both with an empty body: the apps then use their cached copy or
     * the initials.
     */
    public function show(Request $request, int $user, int $version): Response
    {
        if (! $request->hasValidSignatureWhileIgnoring(['size'])) {
            return response('', 403);
        }
        $size = (int) $request->query('size', (string) Avatars::SIZES[0]);
        $bytes = $this->avatars->read($user, $version, $size);
        if ($bytes === null) {
            return response('', 404);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Content-Length' => (string) strlen($bytes),
            // A version is one picture for good.
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** POST /api/account/avatar — multipart `image`; `sessions:write`, 10 changes an hour with removing. */
    public function store(Request $request): JsonResponse
    {
        FieldErrors::validate($request->all(), ['image' => ['required', 'file']]);
        /** @var UploadedFile $file */
        $file = $request->file('image');
        $user = $this->owner($request);
        $this->avatars->store($user, $file);

        return response()->json(Avatars::of($user));
    }

    /** DELETE /api/account/avatar — the stored copies go too. */
    public function destroy(Request $request): Response
    {
        $this->avatars->remove($this->owner($request));

        return response()->noContent();
    }

    private function owner(Request $request): User
    {
        /** @var Device $device */
        $device = $request->user();

        return User::query()->findOrFail($device->user_id);
    }
}
