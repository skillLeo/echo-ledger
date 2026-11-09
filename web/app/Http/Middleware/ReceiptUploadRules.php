<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire's temporary uploads (since v1.7): an upload sent from the Send receipt page takes only what a receipt can
 * be (JPEG, PNG, WebP or PDF, at most 5 MB), before anything is kept even for a moment. Other console uploads (a
 * project's documents, a settings file) keep config/livewire.php's rules. A forged page address only makes the check
 * stricter; PaymentProofs reads the type from the bytes again either way.
 */
final class ReceiptUploadRules
{
    public const RULES = ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf'];

    public function handle(Request $request, Closure $next): Response
    {
        $from = (string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);
        if (preg_match('#/settings/plan/requests/[0-9A-Za-z]{26}/receipt$#', $from) === 1) {
            config(['livewire.temporary_file_upload.rules' => self::RULES]);
        }

        return $next($request);
    }
}
