<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Sharing\LinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** docs/API.md "Links in the apps" (since v1.5): what a share, project or request link is for the caller. */
final class LinkController extends Controller
{
    /** GET /api/links/resolve?url=… — `404 not_found` for anything the app should open as a web page instead. */
    public function resolve(Request $request, LinkResolver $resolver): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);
        /** @var Device $device */
        $device = $request->user();
        $target = $resolver->resolve($device, (string) $data['url']);
        if ($target === null) {
            throw new ApiError(404, 'not_found', 'This link cannot be opened in the app.');
        }

        return response()->json($target);
    }
}
