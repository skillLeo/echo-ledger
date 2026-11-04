<?php

declare(strict_types=1);

namespace App\Services\Sharing;

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Scopes\WorkspaceScope;
use App\Models\SentItem;
use App\Services\Projects\ProjectTree;
use App\Services\ShareLinks;

/**
 * GET /api/links/resolve (docs/API.md "Links in the apps", since v1.5): what a `/s/`, `/p/` or `/r/` link of this
 * server is for the asking device, so the app opens it in place. The token is checked as its page checks it (known,
 * not revoked, not expired, its recording or project still there); the answer only names what the caller may open
 * anyway: its workspace's recordings, projects and request links, and what was sent to it. Anything else is null, the
 * same for every reason.
 */
final class LinkResolver
{
    public function __construct(
        private readonly ShareLinks $shares,
        private readonly RequestLinks $requests,
        private readonly Teammates $teammates,
        private readonly ProjectTree $projects,
    ) {}

    /** @return array<string, string>|null */
    public function resolve(Device $device, string $url): ?array
    {
        $parts = parse_url(trim($url));
        $app = parse_url((string) config('app.url'));
        if (! is_array($parts) || ! is_array($app) || ! isset($parts['host'], $app['host'], $parts['path'])
            || strcasecmp($parts['host'], $app['host']) !== 0 || ($parts['port'] ?? null) !== ($app['port'] ?? null)
            || preg_match('#^/(s|p|r)/([A-Za-z0-9_-]{43})(?:/([0-9A-Za-z]{26}))?/?$#', $parts['path'], $m) !== 1) {
            return null;
        }
        [, $kind, $token] = $m;
        $sessionId = $m[3] ?? null;

        return match ($kind) {
            's' => $sessionId === null ? $this->recordingLink($device, $token) : null,
            'p' => $this->projectLink($device, $token, $sessionId),
            default => $sessionId === null ? $this->requestLink($device, $token) : null,
        };
    }

    /** @return array<string, string>|null */
    private function recordingLink(Device $device, string $token): ?array
    {
        $link = $this->shares->find($token);
        $session = $link !== null && $link->kind() === 'session' && $link->isActive() ? $link->captureSession : null;

        return $session !== null && $this->canOpen($device, $session) ? ['kind' => 'session', 'session_id' => $session->id] : null;
    }

    /** @return array<string, string>|null */
    private function projectLink(Device $device, string $token, ?string $sessionId): ?array
    {
        $link = $this->shares->find($token);
        $project = $link !== null && $link->kind() === 'project' && $link->isActive() ? $link->client : null;
        if (! $project instanceof Client) {
            return null;
        }
        if ($sessionId !== null) {
            // One recording of the project's page (/p/{token}/{recording}).
            $session = CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)
                ->whereIn('client_id', $this->projects->withDescendants($project))->find($sessionId);

            return $session instanceof CaptureSession && $this->canOpen($device, $session) ? ['kind' => 'session', 'session_id' => $session->id] : null;
        }
        if ($project->workspace_id === $device->workspace_id) {
            return ['kind' => 'project', 'client_id' => $project->id];
        }
        $item = SentItem::query()->where('to_user_id', $device->user_id)->where('kind', 'project')->where('client_id', $project->id)->first();

        return $item instanceof SentItem ? ['kind' => 'shared', 'shared_id' => $item->id] : null;
    }

    /** @return array<string, string>|null */
    private function requestLink(Device $device, string $token): ?array
    {
        $link = $this->requests->usable($token);

        return $link !== null && $link->workspace_id === $device->workspace_id ? ['kind' => 'request', 'request_link_id' => $link->id] : null;
    }

    /** A recording of the device's workspace, or one sent to its person. */
    private function canOpen(Device $device, CaptureSession $session): bool
    {
        return ($session->workspace_id === $device->workspace_id && ! $session->trashed())
            || $this->teammates->readable($device->user, $session->id) !== null;
    }
}
