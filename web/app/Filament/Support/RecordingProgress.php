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

    /** Each step's name while it waits, while it runs and once it is done. */
    public const WORDS = [
        self::UPLOAD => ['Upload', 'Uploading', 'Uploaded'],
        self::TRANSCRIBE => ['Transcribe', 'Transcribing', 'Transcribed'],
        self::IMPROVE => ['Improve', 'Improving', 'Improved'],
    ];

    /**
     * @return array{active: string, steps: list<array{key: string, label: string, state: string, detail: string|null, percent: int|null}>, summary: string}|null
     */
    public static function for(CaptureSession $session): ?array
    {
        // Audio only (since v1.8): nothing is under way until the person asks for the transcript.
        if (in_array($session->status, [SessionStatus::Recording, SessionStatus::Failed, SessionStatus::AudioOnly], true) || $session->hasNoAudio()) {
            return null;
        }
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();
        if ($final?->status === TranscriptStatus::Failed) {
            return null;
        }
        $uploading = $session->status === SessionStatus::Uploading;
        $transcribing = ! $uploading && ($final === null || in_array($final->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true));
        // The views' state from what is stored (no lines are read for a row of the list). Since U19 a view about to be
        // made counts as being made, so "Converting to Roman Urdu…" shows from the moment the transcript is there.
        $views = $final?->status === TranscriptStatus::Completed ? TranscriptViews::rowSummary($final) : null;
        $improving = ! $uploading && ! $transcribing && $views !== null && $views['status'] === 'making';
        if (! $uploading && ! $transcribing && ! $improving) {
            return null;
        }
        $view = self::improvingView($session, $final, $views);

        // Since v1.6 (P5.4) the phone's and the desktop's words: three steps, each named for its state, and one line.
        $upload = ['key' => self::UPLOAD, 'label' => self::word(self::UPLOAD, $uploading ? 'active' : 'done'), 'state' => $uploading ? 'active' : 'done', 'detail' => null, 'percent' => null];
        if ($uploading) {
            $upload['percent'] = self::uploadPercent($session);
            $upload['detail'] = $upload['percent'].'%';
        }
        $transcribeState = $uploading ? 'waiting' : ($transcribing ? 'active' : 'done');
        $transcribe = ['key' => self::TRANSCRIBE, 'label' => self::word(self::TRANSCRIBE, $transcribeState), 'state' => $transcribeState, 'detail' => null, 'percent' => null];
        if ($transcribing) {
            $progress = TranscriptResource::progress($final);
            $transcribe['detail'] = self::transcribeDetail($progress);
            $transcribe['percent'] = $progress !== null && $progress['total'] > 1 ? (int) floor($progress['done'] * 100 / $progress['total']) : null;
        }
        $improveState = $improving ? 'active' : 'waiting';
        $started = false;
        $improve = ['key' => self::IMPROVE, 'label' => self::word(self::IMPROVE, $improveState), 'state' => $improveState, 'detail' => $view !== null ? __(TranscriptViews::VIEWS[$view]) : null, 'percent' => null];
        if ($improving && $view !== null) {
            $lines = is_array($views['progress']) ? ($views['progress'][$view] ?? null) : null;
            $improve['percent'] = is_array($lines) && $lines['total'] > 0 ? (int) floor($lines['done'] * 100 / $lines['total']) : 0;
            $improve['detail'] = __(TranscriptViews::VIEWS[$view]).'… '.$improve['percent'].'%';
            // Before the job has counted the lines (or started) there is no share to give yet.
            $started = is_array($lines) && $lines['total'] > 0;
        }
        $steps = [$upload, $transcribe, $improve];
        $active = $uploading ? self::UPLOAD : ($transcribing ? self::TRANSCRIBE : self::IMPROVE);
        $current = collect($steps)->firstWhere('key', $active);

        return [
            'active' => $active,
            'steps' => $steps,
            // In the console's language (since W-213; English reads exactly as before).
            'summary' => match ($active) {
                self::UPLOAD => __('Uploading :percent%', ['percent' => $upload['percent']]),
                self::TRANSCRIBE => __('Transcribing').($current['detail'] !== null ? ' · '.$current['detail'] : ''),
                // Since U19 the words the person asked for: "Converting to Roman Urdu… 60%".
                default => $view !== null ? self::converting($view, $started ? $current['percent'] : null) : __('Improving…'),
            },
        ];
    }

    /** "Converting to Roman Urdu… 60%", or without the share before the first lines are done. */
    public static function converting(string $view, ?int $percent): string
    {
        $name = __(TranscriptViews::VIEWS[$view] ?? $view);

        return $percent === null ? __('Converting to :view…', ['view' => $name]) : __('Converting to :view… :percent%', ['view' => $name, 'percent' => $percent]);
    }

    /**
     * Since U19: the view [$session] opens in (Roman Urdu for Urdu and mixed calls) while it is converted, from the moment
     * its transcript is there ("Converting to Roman Urdu… 60%", the share once the job counted the lines), or when it
     * could not be made ("Couldn't convert to Roman Urdu": it failed, or went quiet for 20 minutes). Null otherwise,
     * also while the transcript itself is still moving. What the Library rows and the recording page show.
     *
     * @return array{state: string, view: string, label: string, percent: int|null}|null
     */
    public static function conversion(CaptureSession $session): ?array
    {
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();
        $views = $final?->status === TranscriptStatus::Completed ? TranscriptViews::rowSummary($final) : null;
        if ($views === null || ! isset(TranscriptViews::VIEWS[$views['default']]) || ! in_array($views['status'], ['making', 'failed'], true)) {
            return null;
        }
        $view = $views['default'];
        if ($views['status'] === 'failed') {
            return ['state' => 'failed', 'view' => $view, 'label' => __('Couldn\'t convert to :view', ['view' => __(TranscriptViews::VIEWS[$view])]), 'percent' => null];
        }
        $lines = is_array($views['progress']) ? ($views['progress'][$view] ?? null) : null;
        $percent = is_array($lines) && $lines['total'] > 0 ? (int) floor($lines['done'] * 100 / $lines['total']) : null;

        return ['state' => 'making', 'view' => $view, 'label' => self::converting($view, $percent), 'percent' => $percent];
    }

    /**
     * The recording page's slim bar: "Converting to Roman Urdu… 60%" while the view it opens in is made, "Couldn't
     * convert to Roman Urdu" (with Try again) when it could not be; null otherwise.
     *
     * @return array{label: string, percent: int, failed: bool}|null
     */
    public static function pageBar(CaptureSession $session): ?array
    {
        $bar = self::improvingBar($session);
        if ($bar !== null) {
            return [...$bar, 'failed' => false];
        }
        $conversion = self::conversion($session);

        return $conversion !== null && $conversion['state'] === 'failed' ? ['label' => $conversion['label'], 'percent' => 0, 'failed' => true] : null;
    }

    /** "Upload", "Uploading" or "Uploaded" (and the same for Transcribe and Improve), as the phone and the desktop say it. */
    public static function word(string $step, string $state): string
    {
        $words = self::WORDS[$step];

        return match ($state) {
            'active' => $words[1],
            'done' => $words[2],
            default => $words[0],
        };
    }

    /**
     * A step's name as shown, in the console's language. "Transcribe" as a step (ٹرانسکرپشن) has its own key, apart
     * from the Transcribe button (ٹرانسکرائب کریں); English reads "Transcribe" either way (W-213 visual).
     */
    public static function stageLabel(string $word): string
    {
        if ($word === self::WORDS[self::TRANSCRIBE][0]) {
            return trans()->has('Transcribe (stage)') ? (string) __('Transcribe (stage)') : $word;
        }

        return (string) __($word);
    }

    /**
     * "Converting to Roman Urdu… 60%" while the view the recording opens in is made; null otherwise.
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
     * "part 2 of 3 · about 1 min left", "about 40 s left" or null: the phone's words after "Transcribing · ".
     *
     * @param  array{done: int, total: int, eta_seconds: int|null}|null  $progress
     */
    public static function transcribeDetail(?array $progress): ?string
    {
        if ($progress === null) {
            return null;
        }
        $parts = $progress['total'] > 1 ? __('part :n of :total', ['n' => min($progress['total'], $progress['done'] + 1), 'total' => $progress['total']]) : null;
        $left = self::timeLeft($progress['eta_seconds']);
        $detail = implode(' · ', array_filter([$parts, $left]));

        return $detail === '' ? null : $detail;
    }

    /** "about 20 s left" (to 5 s) under a minute, then "about 3 min left", as the phone rounds it; null without a measure. */
    public static function timeLeft(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }
        $seconds = max(0, $seconds);
        $amount = $seconds < 60 ? __(':n s', ['n' => intdiv(max(5, $seconds) + 4, 5) * 5]) : __(':n min', ['n' => intdiv($seconds + 30, 60)]);

        return __('about :amount left', ['amount' => $amount]);
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
