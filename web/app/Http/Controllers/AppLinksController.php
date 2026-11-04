<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Android App Links (docs/API.md "Links in the apps", since v1.5): the statement that lets the phone app open this
 * server's share, project and request links itself. The package and signing fingerprints come from the config.
 */
final class AppLinksController extends Controller
{
    /** GET /.well-known/assetlinks.json */
    public function __invoke(): JsonResponse
    {
        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => (string) config('echoledger.android_app.package'),
                'sha256_cert_fingerprints' => array_values((array) config('echoledger.android_app.fingerprints')),
            ],
        ]], 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
