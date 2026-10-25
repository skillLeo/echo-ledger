<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\RecordingController;
use App\Models\ShareLink;
use App\Models\Transcript;
use App\Services\ShareLinks;
use App\Services\TranscriptExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The page behind a share link (`/s/{token}`): view-only transcript, optional audio, optional password. Unknown,
 * expired and revoked links all get the same "no longer available" page.
 */
final class PublicShareController extends Controller
{
    private const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(private readonly ShareLinks $shares, private readonly TranscriptExporter $exporter) {}

    public function show(Request $request, string $token): Response
    {
        $link = $this->usable($token);
        if ($link === null) {
            return $this->gone();
        }
        if (! $this->unlocked($request, $link)) {
            return response()->view('share.password', ['token' => $token, 'error' => null], 200, self::HEADERS);
        }
        $this->shares->log($link, 'viewed', $request);
        $session = $link->captureSession;
        $transcript = $session->transcripts()->where('status', TranscriptStatus::Completed->value)->get()
            ->sortBy(fn (Transcript $t): int => $t->kind === TranscriptKind::Final ? 0 : 1)->first();
        $primary = $session->recordings()->where('role', 'primary')->first();

        return response()->view('share.show', [
            'token' => $token,
            'session' => $session,
            'lines' => $transcript instanceof Transcript ? $this->exporter->lines($transcript, $session) : [],
            'transcribing' => ! $transcript instanceof Transcript,
            'audio' => $link->include_audio && $primary !== null && $primary->isReady(),
            'expiresAt' => $link->expires_at,
        ], 200, self::HEADERS);
    }

    public function unlock(Request $request, string $token): RedirectResponse|Response
    {
        $link = $this->usable($token);
        if ($link === null) {
            return $this->gone();
        }
        $password = (string) $request->input('password', '');
        if (! $this->shares->passwordMatches($link, $password)) {
            $this->shares->log($link, 'wrong_password', $request);

            return response()->view('share.password', ['token' => $token, 'error' => 'That password is not right.'], 422, self::HEADERS);
        }
        $request->session()->put('share_unlocked.'.$link->id, true);

        return redirect()->to('/s/'.$token)->withHeaders(self::HEADERS);
    }

    public function audio(Request $request, string $token): BinaryFileResponse
    {
        $link = $this->usable($token);
        if ($link === null || ! $link->include_audio || ! $this->unlocked($request, $link)) {
            abort(404);
        }
        $recording = $link->captureSession->recordings()->where('role', 'primary')->first();
        abort_if($recording === null || ! $recording->isReady(), 404);
        // A player asks for many ranges: one log line when it starts from the beginning.
        $range = (string) $request->header('Range', '');
        if ($range === '' || str_starts_with($range, 'bytes=0-')) {
            $this->shares->log($link, 'audio', $request);
        }

        $response = RecordingController::streamDecrypted($recording);
        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function usable(string $token): ?ShareLink
    {
        $link = $this->shares->find($token);

        return $link !== null && $link->isActive() ? $link : null;
    }

    private function unlocked(Request $request, ShareLink $link): bool
    {
        return $link->password_hash === null || $request->session()->get('share_unlocked.'.$link->id) === true;
    }

    private function gone(): Response
    {
        return response()->view('share.gone', [], 404, self::HEADERS);
    }
}
