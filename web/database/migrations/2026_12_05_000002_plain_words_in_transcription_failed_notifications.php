<?php

declare(strict_types=1);

use App\Enums\TranscriptErrorCode;
use App\Models\CaptureSession;
use App\Services\ActivityFeed;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Since v1.8.1 (D-131): a "Transcription failed" notification said why in the engine's own words ("Call with Omar:
 * The speech provider could not transcribe this recording: Gemini could not read the audio."), and the console's bell
 * and Activity, the apps' Activity and their system notifications showed it. New ones say why in plain words per error
 * code (ActivityFeed::failedBody); this rewrites the ones already sent the same way, from the recording's name and its
 * final transcript's error code. A notification whose recording is gone keeps only the plain reason. The engine's own
 * words stay with the transcript, for the operator, so down() has nothing to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->where('data->viewData->kind', 'transcript_failed')->lazyById(200, 'id')->each(function (object $row): void {
            $data = json_decode((string) $row->data, true);
            if (! is_array($data)) {
                return;
            }
            $sessionId = $data['viewData']['session_id'] ?? null;
            $session = is_string($sessionId) ? CaptureSession::query()->withoutGlobalScopes()->find($sessionId) : null;
            $code = $session instanceof CaptureSession
                ? DB::table('transcripts')->where('capture_session_id', $session->id)->where('kind', 'final')->value('error_code') : null;
            $body = $session instanceof CaptureSession
                ? ActivityFeed::failedBody($session->displayTitle(), is_string($code) ? $code : null)
                : TranscriptErrorCode::plainWords(null);
            // Written as ActivityFeed writes it: escaped, and marked so.
            $data['body'] = e($body);
            $data['viewData'] = [...(is_array($data['viewData'] ?? null) ? $data['viewData'] : []), 'escaped' => true];
            DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
        });
    }

    public function down(): void
    {
        // Nothing to put back: the engine's own words were never meant for people and stay with each transcript.
    }
};
