<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Http\Controllers\Api\RecordingController;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Scopes\WorkspaceScope;
use App\Models\ShareLink;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Projects\ProjectTree;
use App\Services\ShareLinks;
use App\Services\Sharing\ShareDownloads;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The pages behind a share link: `/s/{token}` (one recording: view-only transcript, optional player, optional
 * download) and since v1.5 `/p/{token}` (a project's recordings, each opening as `/s/` would). A link may need its
 * password, or (people-only) the console sign-in with one of the emails it names; anyone else gets a refusal without
 * any of the content. Unknown, expired and revoked links all get the same "no longer available" page. Everything
 * the page reads is the link's workspace's (the signed-in person may be in another one).
 */
final class PublicShareController extends Controller
{
    private const HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'no-store, private',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(
        private readonly ShareLinks $shares,
        private readonly TranscriptExporter $exporter,
        private readonly ShareDownloads $downloads,
        private readonly ProjectTree $projects,
    ) {}

    // ---- One recording: /s/{token} ---------------------------------------------------------------------------------

    public function show(Request $request, string $token): Response
    {
        return $this->guarded($request, $token, 'session', fn (ShareLink $link): Response => $this->recordingPage($link, $link->captureSession, '/s/'.$token, null, 'viewed', $request));
    }

    public function unlock(Request $request, string $token): RedirectResponse|Response
    {
        return $this->unlockLink($request, $token, 'session', '/s/'.$token);
    }

    public function audio(Request $request, string $token): BinaryFileResponse
    {
        return $this->file($request, $token, 'session', fn (ShareLink $link): BinaryFileResponse => $this->stream($request, $link, $link->captureSession));
    }

    /** Since v1.5: the audio and the transcript in one ZIP, for a link that allows downloads. */
    public function download(Request $request, string $token): BinaryFileResponse
    {
        return $this->file($request, $token, 'session', fn (ShareLink $link): BinaryFileResponse => $this->zip($request, $link, $link->captureSession));
    }

    // ---- A project: /p/{token} (since v1.5) ------------------------------------------------------------------------

    public function project(Request $request, string $token): Response
    {
        return $this->guarded($request, $token, 'project', function (ShareLink $link) use ($request, $token): Response {
            $this->shares->log($link, 'viewed', $request);
            /** @var Client $project */
            $project = $link->client;
            $folders = Client::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('id', $this->projects->withDescendants($project))->pluck('name', 'id');
            $sessions = $this->projectSessions($link)->latest('started_at')->limit(500)->get();

            return response()->view('share.project', [
                'project' => $project,
                'folders' => $folders,
                'sessions' => $sessions,
                'token' => $token,
                'expiresAt' => $link->expires_at,
                'pageUrl' => url('/p/'.$token),
            ], 200, self::HEADERS);
        });
    }

    public function unlockProject(Request $request, string $token): RedirectResponse|Response
    {
        return $this->unlockLink($request, $token, 'project', '/p/'.$token);
    }

    public function projectSession(Request $request, string $token, string $session): Response
    {
        return $this->guarded($request, $token, 'project', function (ShareLink $link) use ($request, $token, $session): Response {
            $recording = $this->sessionInProject($link, $session);

            return $recording === null ? $this->gone()
                : $this->recordingPage($link, $recording, "/p/{$token}/{$recording->id}", '/p/'.$token, 'opened', $request);
        });
    }

    public function projectAudio(Request $request, string $token, string $session): BinaryFileResponse
    {
        return $this->file($request, $token, 'project', fn (ShareLink $link): BinaryFileResponse => $this->stream($request, $link, $this->sessionInProject($link, $session)));
    }

    public function projectDownload(Request $request, string $token, string $session): BinaryFileResponse
    {
        return $this->file($request, $token, 'project', fn (ShareLink $link): BinaryFileResponse => $this->zip($request, $link, $this->sessionInProject($link, $session)));
    }

    // ---- Pages -----------------------------------------------------------------------------------------------------

    /**
     * Runs $page for a usable link of the kind, as its workspace, once the link's password or people check passed;
     * otherwise the password page, the sign-in page or the refusal (none of them shows any content).
     *
     * @param  Closure(ShareLink): Response  $page
     */
    private function guarded(Request $request, string $token, string $kind, Closure $page): Response
    {
        $link = $this->usable($token, $kind);
        if ($link === null) {
            return $this->gone();
        }
        $base = ($kind === 'project' ? '/p/' : '/s/').$token;
        if ($link->audience === 'password' && ! $this->unlocked($request, $link)) {
            return response()->view('share.password', ['action' => url($base), 'error' => null, 'project' => $kind === 'project'], 200, self::HEADERS);
        }
        if ($link->audience === 'people') {
            $viewer = $request->user('web');
            if (! $viewer instanceof User) {
                // The console's sign-in comes back here (Filament redirects to the intended address).
                $request->session()->put('url.intended', $request->fullUrl());

                return response()->view('share.signin', ['loginUrl' => Filament::getPanel('admin')->getLoginUrl()], 200, self::HEADERS);
            }
            if (! $link->allows($viewer->email)) {
                return response()->view('share.refused', ['email' => $viewer->email], 403, self::HEADERS);
            }
        }

        return $this->asLinkWorkspace($link, fn (): Response => $page($link));
    }

    /** @param  Closure(ShareLink): BinaryFileResponse  $send */
    private function file(Request $request, string $token, string $kind, Closure $send): BinaryFileResponse
    {
        $link = $this->usable($token, $kind);
        abort_if($link === null || ! $this->open($request, $link), 404);

        return $this->asLinkWorkspace($link, fn (): BinaryFileResponse => $send($link));
    }

    private function unlockLink(Request $request, string $token, string $kind, string $base): RedirectResponse|Response
    {
        $link = $this->usable($token, $kind);
        if ($link === null || $link->audience !== 'password') {
            return $this->gone();
        }
        $password = (string) $request->input('password', '');
        if (! $this->shares->passwordMatches($link, $password)) {
            $this->shares->log($link, 'wrong_password', $request);

            return response()->view('share.password', ['action' => url($base), 'error' => 'That password is not right.', 'project' => $kind === 'project'], 422, self::HEADERS);
        }
        $request->session()->put('share_unlocked.'.$link->id, true);

        return redirect()->to($base)->withHeaders(self::HEADERS);
    }

    private function recordingPage(ShareLink $link, ?CaptureSession $session, string $base, ?string $back, string $outcome, Request $request): Response
    {
        if ($session === null) {
            return $this->gone();
        }
        $this->shares->log($link, $outcome, $request);
        $transcript = $session->transcripts()->where('status', TranscriptStatus::Completed->value)->get()
            ->sortBy(fn (Transcript $t): int => $t->kind === TranscriptKind::Final ? 0 : 1)->first();
        $primary = $session->recordings()->where('role', 'primary')->first();
        $hasAudio = $primary !== null && $primary->isReady();

        return response()->view('share.show', [
            'session' => $session,
            'lines' => $transcript instanceof Transcript ? $this->exporter->lines($transcript, $session, $link->view) : [],
            // Urdu and Arabic lines get their own letter forms (not while a Latin-letter view is shown).
            'languageCode' => $transcript instanceof Transcript && $link->view === null ? LanguageChoice::normalize($transcript->language) : null,
            // Show as: say which view the reader sees (only when it is ready; otherwise the original words show).
            'viewLabel' => $transcript instanceof Transcript && $link->view !== null && app(TranscriptViews::class)->textsFor($transcript, $link->view) !== null
                ? TranscriptViews::VIEWS[$link->view] ?? null : null,
            'transcribing' => ! $transcript instanceof Transcript,
            'audioUrl' => $link->include_audio && $hasAudio ? url($base.'/audio') : null,
            'downloadUrl' => $link->allow_download && ($hasAudio || $transcript instanceof Transcript) ? url($base.'/download') : null,
            'backUrl' => $back !== null ? url($back) : null,
            'expiresAt' => $link->expires_at,
            'pageUrl' => url($base),
        ], 200, self::HEADERS);
    }

    private function stream(Request $request, ShareLink $link, ?CaptureSession $session): BinaryFileResponse
    {
        abort_if($session === null || ! $link->include_audio, 404);
        $recording = $session->recordings()->where('role', 'primary')->first();
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

    private function zip(Request $request, ShareLink $link, ?CaptureSession $session): BinaryFileResponse
    {
        abort_if($session === null || ! $link->allow_download, 404);
        $file = $this->downloads->zip($link, $session);
        abort_if($file === null, 404);
        $this->shares->log($link, 'download', $request);

        $response = response()->download($file['path'], $file['name'], ['Content-Type' => 'application/zip', ...self::HEADERS]);

        return $response->deleteFileAfterSend(true);
    }

    // ---- Lookups ---------------------------------------------------------------------------------------------------

    /** A link of the kind that can be opened now; null for anything else (the same answer for every reason). */
    private function usable(string $token, string $kind): ?ShareLink
    {
        $link = $this->shares->find($token);

        return $link !== null && $link->kind() === $kind && $link->isActive() ? $link : null;
    }

    /** The audio and download routes: the same checks as the page, without pages. */
    private function open(Request $request, ShareLink $link): bool
    {
        return match ($link->audience) {
            'password' => $this->unlocked($request, $link),
            'people' => ($viewer = $request->user('web')) instanceof User && $link->allows($viewer->email),
            default => true,
        };
    }

    private function unlocked(Request $request, ShareLink $link): bool
    {
        return $link->password_hash === null || $request->session()->get('share_unlocked.'.$link->id) === true;
    }

    /**
     * The recordings a project link shows: the project's and its subfolders', still in the Library and kept.
     *
     * @return Builder<CaptureSession>
     */
    private function projectSessions(ShareLink $link): Builder
    {
        /** @var Client $project */
        $project = $link->client;

        return CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)
            ->whereIn('client_id', $this->projects->withDescendants($project))
            ->where('status', '!=', SessionStatus::Recording->value);
    }

    private function sessionInProject(ShareLink $link, string $id): ?CaptureSession
    {
        $session = $this->projectSessions($link)->find($id);

        return $session instanceof CaptureSession ? $session : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function asLinkWorkspace(ShareLink $link, Closure $callback): mixed
    {
        $workspace = $link->workspaceId();

        return $workspace === null ? $callback() : CurrentWorkspace::runAs($workspace, $callback);
    }

    private function gone(): Response
    {
        return response()->view('share.gone', [], 404, self::HEADERS);
    }
}
