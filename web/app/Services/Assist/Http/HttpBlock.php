<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Services\Assist\AssistPlans;

/**
 * The `http` block of a session in HTTP mode (docs/API_ASSIST.md 1.9.0-q): where the app posts its live messages, and
 * the `ready` the relay would have sent first (protocol, mode, allowed modes, practice, limits).
 */
final class HttpBlock
{
    public const PROTOCOL = 1;

    /** The same limits as the relay's, but fewer messages a second: each is a request. */
    public const LIMITS = ['max_message_bytes' => 2097152, 'max_turn_chars' => 4000, 'messages_per_second' => 10];

    public function __construct(private readonly AssistPlans $plans, private readonly ModeChoice $modes) {}

    /**
     * @return array{live_url: string, ready: array<string, mixed>}
     */
    public function block(AssistSession $session): array
    {
        return ['live_url' => self::liveUrl($session), 'ready' => $this->ready($session)];
    }

    /** @return array<string, mixed> */
    public function ready(AssistSession $session): array
    {
        $allowed = array_reverse($this->plans->modes($session->workspace_id, $session->practice));
        $mode = $this->modes->effective($session);
        // What the app is told here is what `mode_changed` is measured from.
        $this->modes->announce($session, $mode);

        return [
            'protocol' => self::PROTOCOL,
            'session_id' => $session->id,
            'mode' => $mode,
            'requested_mode' => $this->modes->requested($session),
            'allowed_modes' => $allowed,
            'resumed' => false,
            'practice' => $session->practice,
            'limits' => self::LIMITS,
        ];
    }

    /** `https://<server>/api/assist/sessions/{id}/live`, from the server's own address, never the request's. */
    public static function liveUrl(AssistSession $session): string
    {
        return rtrim((string) config('app.url'), '/').'/api/assist/sessions/'.$session->id.'/live';
    }
}
