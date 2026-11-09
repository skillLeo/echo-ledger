<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\ApiError;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\ShareLinks;
use App\Services\Sharing\WhatsAppMessage;
use App\Services\Workspaces\ConsoleAccess;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Send to WhatsApp → Link (since v1.6, P5.5) on the Library and the recording page: a share link anyone can open for
 * 7 days (Share's "Anyone with the link", with the audio when there is some), answered with its wa.me address. Only
 * a recording of the person's own Library (the resource's query: another person's is a 404); not in a read-only
 * console; at most 10 a minute per person.
 */
trait SendsToWhatsApp
{
    /**
     * The Show as view being read on the recording page (the player tells the page, with no request of its own), so
     * Text only sends what the person sees.
     */
    public string $readingView = 'original';

    /**
     * [$view]: the Show as view the panel shows, so the link's page opens in it too (only when it is made).
     *
     * @return array{url: string, link: string}|array{error: string}
     */
    public function whatsAppLink(string $sessionId, ?string $view = null): array
    {
        abort_if(ConsoleAccess::readOnly(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $key = 'whatsapp-link:'.$user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return ['error' => 'Too many links in a minute. Try again shortly.'];
        }
        RateLimiter::hit($key, 60);
        $session = CaptureSessionResource::getEloquentQuery()->whereKey($sessionId)->firstOrFail();
        if (! $session instanceof CaptureSession || $session->hasNoAudio()) {
            return ['error' => 'This recording has nothing to share.'];
        }
        try {
            ['url' => $link] = app(ShareLinks::class)->make($session, [
                'audience' => 'public',
                'include_audio' => $session->primaryRecording()->first()?->isReady() === true,
                'allow_download' => false,
                'expires_at' => now()->addSeconds(WhatsAppMessage::LINK_SECONDS),
                'view' => WhatsAppMessage::readyView($session, $view),
            ], $user->name);
        } catch (ApiError $e) {
            return ['error' => $e->getMessage()];
        }

        return ['url' => WhatsAppMessage::url(WhatsAppMessage::linkMessage($session->displayTitle(), $link))['url'], 'link' => $link];
    }
}
