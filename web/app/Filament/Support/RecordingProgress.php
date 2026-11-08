<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\TranscriptStatus;
use App\Http\Resources\TranscriptResource;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\Views\TranscriptViews;

/**
 * Clear progress (since v1.5, Phase 1C, C2–C4): the three steps of a recording as the Library row and the recording page
 * show them, with real numbers: Upload (% of the bytes received), Transcribing (parts done and time left, from the
 * engine's measured speed) and Improving (the view the recording opens in, % of its lines). Null once nothing is moving.
 */
final class RecordingProgress
{
    public const UPLOAD = 'upload';

    public const TRANSCRIBE = 'transcribe';

    public const IMPROVE = 'improve';

    /**
     * @return array{active: string, steps: list<array{key: string, label: string, state: string, detail: string|null, percent: int|null}>, summary: string}|null
     */
    public static function for(CaptureSession $session): ?array
    {
        if (in_array($session->status, [SessionStatus::Recording, SessionStatus::Failed], true) || $session->hasNoAudio()) {
            return null;
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();
        if ($final?->status === TranscriptStatus::Failed) {
            return null;
        }
        $uploading = $session->status === SessionStatus::Uploading;
        $transcribing = ! $uploading && ($final === null || in_array($final->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true));
        // The views' state only when they are being made (a ready one is not read for every row of the list).
        $views = $final?->status === TranscriptStatus::Completed && (($final->meta['views']['status'] ?? null) === 'making')
            ? app(TranscriptViews::class)->state($final) : null;
        $improving = ! $uploading && ! $transcribing && $views !== null && $views['status'] === 'making';
        if (! $uploading && ! $transcribing && ! $improving) {
            return null;
        }
        $view = self::improvingView($session, $final, $views);

        $upload = ['key' => self::UPLOAD, 'label' => 'Upload', 'state' => $uploading ? 'active' : 'done', 'detail' => null, 'percent' => null];
        if ($uploading) {
            $upload['percent'] = self::uploadPercent($session);
            $upload['detail'] = $upload['percent'].' %';
        }
        $transcribe = ['key' => self::TRANSCRIBE, 'label' => 'Transcribing', 'state' => $uploading ? 'waiting' : ($transcribing ? 'active' : 'done'), 'detail' => null, 'percent' => null];
        if ($transcribing) {
            $progress = TranscriptResource::progress($final);
            $transcribe['detail'] = self::transcribeDetail($progress);
            $transcribe['percent'] = $progress !== null && $progress['total'] > 1 ? (int) floor($progress['done'] * 100 / $progress['total']) : null;
        }
        $steps = [$upload, $transcribe];
        if ($view !== null) {
            $improve = ['key' => self::IMPROVE, 'label' => 'Improving', 'state' => $improving ? 'active' : 'waiting', 'detail' => TranscriptViews::VIEWS[$view], 'percent' => null];
            if ($improving) {
                $lines = is_array($views['progress']) ? ($views['progress'][$view] ?? null) : null;
                $improve['percent'] = is_array($lines) && $lines['total'] > 0 ? (int) floor($lines['done'] * 100 / $lines['total']) : 0;
                $improve['detail'] = TranscriptViews::VIEWS[$view].' '.$improve['percent'].' %';
            }
            $steps[] = $improve;
        }
        $active = $uploading ? self::UPLOAD : ($transcribing ? self::TRANSCRIBE : self::IMPROVE);
        $current = collect($steps)->firstWhere('key', $active);

        return [
            'active' => $active,
            'steps' => $steps,
            'summary' => match ($active) {
                self::UPLOAD => 'Uploading '.$upload['percent'].' %',
                self::TRANSCRIBE => 'Transcribing'.($current['detail'] !== null ? ' · '.$current['detail'] : ''),
                default => 'Improving '.TranscriptViews::VIEWS[(string) $view].'… '.$current['percent'].' %',
            },
        ];
    }

    /**
     * "Improving Roman Urdu… 60 %" while the view the recording opens in is made; null otherwise.
     *
     * @return array{label: string, percent: int}|null
     */
    public static function improvingBar(CaptureSession $session): ?array
    {
        $progress = self::for($session);
        if ($progress === null || $progress['active'] !== self::IMPROVE) {
            return null;
        }
        $step = $progress['steps'][2];

        return ['label' => $progress['summary'], 'percent' => (int) $step['percent']];
    }

    /** The bytes received of the primary recording, as a whole percent (chunks received of chunks). */
    public static function uploadPercent(CaptureSession $session): int
    {
        $recording = $session->recordings()->where('role', RecordingRole::Primary->value)->first();
        if (! $recording instanceof Recording || $recording->declared_size_bytes <= 0) {
            return 0;
        }
        if ($recording->isReady()) {
            return 100;
        }
        $chunks = count($recording->received_chunks ?? []);
        $received = min($recording->declared_size_bytes, $chunks * max(1, $recording->chunk_size_bytes));

        return min(99, (int) floor($received * 100 / $recording->declared_size_bytes));
    }

    /**
     * "Part 2 of 3 · about 1 min left", "About 40 s left" or null.
     *
     * @param  array{done: int, total: int, eta_seconds: int|null}|null  $progress
     */
    public static function transcribeDetail(?array $progress): ?string
    {
        if ($progress === null) {
            return null;
        }
        $parts = $progress['total'] > 1 ? 'Part '.min($progress['total'], $progress['done'] + 1).' of '.$progress['total'] : null;
        $left = self::timeLeft($progress['eta_seconds']);

        return match (true) {
            $parts !== null && $left !== null => $parts.' · '.$left,
            $parts !== null => $parts,
            $left !== null => ucfirst($left),
            default => null,
        };
    }

    public static function timeLeft(?int $seconds): ?string
    {
        return match (true) {
            $seconds === null => null,
            $seconds < 10 => 'almost done',
            $seconds < 60 => 'about '.(int) (ceil($seconds / 10) * 10).' s left',
            $seconds < 3600 => 'about '.(int) ceil($seconds / 60).' min left',
            default => 'about '.round($seconds / 3600, 1).' h left',
        };
    }

    /**
     * The view the recording opens in, when it is made at once (Roman Urdu for Urdu calls by default): the one the
     * Improving step is about. Before the transcript is done, the recording's language says.
     *
     * @param  array<string, mixed>|null  $views
     */
    private static function improvingView(CaptureSession $session, ?Transcript $final, ?array $views): ?string
    {
        if ($final?->status === TranscriptStatus::Completed) {
            $default = (string) ($views['default'] ?? 'original');

            return $default !== 'original' && isset(TranscriptViews::VIEWS[$default]) ? $default : null;
        }
        $views = app(TranscriptViews::class);
        if (! $views->configured()) {
            return null;
        }
        $probe = new Transcript(['language' => $final->language ?? $session->language]);
        $language = TranscriptViews::sourceLanguage($probe);
        $default = TranscriptViews::defaultView(TranscriptViews::available($probe), $language);

        return $default !== 'original' ? $default : null;
    }
}
