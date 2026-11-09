<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Services\Entitlements\PaymentProofs;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's copy of a person's own payment receipt (since v1.7, docs/API.md "Payment receipts"): only the person
 * who sent it (anyone else, a teammate too, gets the same 404 as an unknown id). Never cached; an image opens in the
 * browser, a PDF always downloads, in a sandbox.
 */
final class PaymentProofMediaController
{
    public function __invoke(string $proof, string $variant): Response
    {
        $file = UpgradeRequestProof::query()->whereKey($proof)->first();
        abort_if($file === null, 404);
        abort_unless(UpgradeRequest::query()->whereKey((string) $file->getAttribute('upgrade_request_id'))->where('user_id', (int) auth()->id())->exists(), 404);

        $read = app(PaymentProofs::class)->read($file, $variant);
        $inline = (bool) $read['inline'];
        $headers = [
            'Content-Type' => (string) $read['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes((string) $read['filename'], '"\\').'"',
        ];
        if (! $inline) {
            $headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }

        return response((string) $read['bytes'], 200, $headers);
    }
}
