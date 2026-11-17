<?php

declare(strict_types=1);

namespace App\Services\CallRules;

use App\Enums\SourceApp;
use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\FinalTranscriptCoordinator;
use Illuminate\Support\Facades\DB;

/**
 * The choice for one recording (contract 2.1.0-a): Transcribe or Keep audio, and who decided. A recording made before
 * 2.1.0-a, an import, a request upload and a voice note have none and are transcribed as before.
 */
final class SessionChoice
{
    public const CHOICES = ['transcribe', 'keep_audio'];

    /** Who decided: a rule, the person, the 10-second countdown, or the server's default at upload. */
    public const BY = ['rule', 'user', 'timeout', 'default'];

    /** Records the choice a device sent with its create or finish (a rule, or the countdown). */
    public function fromDevice(CaptureSession $session, ?string $choice, ?string $by): void
    {
        if ($choice === null || $session->choice !== null) {
            return;
        }
        $by = in_array($by, self::BY, true) ? $by : 'rule';
        $session->forceFill(['choice' => $choice, 'choice_by' => $by, 'choice_at' => now(),
            'needs_action' => $choice === 'keep_audio' && $by === 'timeout'])->saveQuietly();
        ChoiceLog::record($session, $choice === 'transcribe' ? ChoiceLog::TRANSCRIBED : ChoiceLog::KEPT, $by);
    }

    /**
     * Whether the upload's completion may start the final transcript. With no choice the person's default decides:
     * none stored or `transcribe` → yes (legacy); `ask`, `keep_audio` or `dont_record` → the recording is kept as audio.
     */
    public function mayTranscribe(CaptureSession $session): bool
    {
        if ($session->choice === 'transcribe') {
            return true;
        }
        if ($session->choice === 'keep_audio') {
            return false;
        }
        if (! self::isCall($session)) {
            return true;
        }
        $user = $session->user ?? User::query()->find($session->user_id);
        $default = $user?->after_call_default;
        if ($default === null || ($default === 'transcribe' && app(CallRules::class)->autoTranscribe($user))) {
            return true;
        }
        $session->forceFill(['choice' => 'keep_audio', 'choice_by' => 'default', 'choice_at' => now(), 'needs_action' => true])->saveQuietly();
        ChoiceLog::record($session, ChoiceLog::KEPT, 'default');

        return false;
    }

    /** POST /api/sessions/{id}/choice for transcribe or keep_audio (delete goes through the usual delete). */
    public function choose(CaptureSession $session, string $choice, string $by): CaptureSession
    {
        if ($choice === 'keep_audio' && self::hasTranscript($session)) {
            throw new ApiError(409, 'already_transcribed', 'This recording is already transcribed.');
        }
        $by = in_array($by, ['user', 'timeout'], true) ? $by : 'user';
        DB::transaction(function () use ($session, $choice, $by): void {
            $changed = $session->choice !== $choice;
            $session->forceFill(['choice' => $choice, 'choice_by' => $by, 'choice_at' => $changed ? now() : ($session->choice_at ?? now()),
                'needs_action' => $choice === 'keep_audio' && $by === 'timeout'])->save();
            if ($changed) {
                ChoiceLog::record($session, $choice === 'transcribe' ? ChoiceLog::TRANSCRIBED : ChoiceLog::KEPT, $by);
            }
        });
        if ($choice === 'transcribe') {
            app(FinalTranscriptCoordinator::class)->maybeStart($session->refresh());
        }

        return $session->refresh();
    }

    /** A phone or computer capture: the after-call choice applies. Imports, requests and voice notes are as before. */
    public static function isCall(CaptureSession $session): bool
    {
        return $session->origin === null && $session->source_app !== SourceApp::VoiceNote;
    }

    public static function hasTranscript(CaptureSession $session): bool
    {
        return $session->finalTranscript()->where('status', '!=', TranscriptStatus::Failed->value)->exists();
    }
}
