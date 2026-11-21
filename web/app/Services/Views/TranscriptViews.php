<?php

declare(strict_types=1);

namespace App\Services\Views;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\MakeTranscriptViewsJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\Entitlements\Entitlements;
use App\Services\FastPath;
use App\Services\SettingsRepository;
use App\Services\ShortNoteCheck;
use App\Services\Titles\AutoTitles;
use App\Services\Workspaces\PlanLimits;
use App\Stt\LanguageChoice;
use App\Stt\ScriptCheck;
use App\Support\ProviderText;
use App\Support\UnsureMarkers;
use App\Support\UrduText;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * "Show as" (since v1.2): Roman Urdu, اردو and English reading views of a finished transcript, made once with the Claude
 * API on the queue and kept with the transcript (`meta.views`). They are labelled as views everywhere; the transcript
 * itself never changes.
 *
 * Since v1.3 (plan A3): Roman Urdu is how Urdu and mixed calls open by default (console setting per language), so the
 * views are made as soon as the transcript is finished, and again after an edit (an edit makes them stale). Each line
 * is checked ([failures]: markers kept, a transliteration keeps its word count within 25 %, no Hindi letters); the lines
 * that fail are asked once more, and a line that still fails keeps the original words (listed in `kept`). The views'
 * text is searchable, so a search in Roman Urdu or in Urdu letters finds the call.
 */
final class TranscriptViews
{
    /** view => label */
    public const VIEWS = ['roman' => 'Roman Urdu', 'urdu' => 'اردو', 'english' => 'English'];

    /** Since U19: an export of several recordings in each one's own view as shown (its default view once made). */
    public const SHOWN = 'shown';

    /** Settings key of the view each language opens in, and its default (Roman Urdu for Urdu, the original otherwise). */
    public const DEFAULT_SETTINGS = ['ur' => 'views.default_ur', 'en' => 'views.default_en', 'ar' => 'views.default_ar'];

    /** "[unclear]", "[unclear: …]" and "[?word]" (a word the engine was unsure of): kept on the same word in every view. */
    private const MARKER = '/\[(?:unclear(?::[^\]]*)?|\?[^\]]+)\]/u';

    /**
     * A transliteration may have this share more words than the original (and always 2): one Urdu word is often two
     * in Roman Urdu ("کیلئے" → "ke liye").
     */
    private const WORD_SLACK = 0.25;

    /** Source characters per request; a chunk whose answer does not fit is split again. */
    public const CHUNK_CHARS = 6000;

    /**
     * A "making" state that moved no line for this long is treated as lost: since U19 it reads as `failed` ("Couldn't
     * convert … · Try again") and may be started again. Also how long after a transcript is finished (or edited) a
     * missing view it opens in counts as on its way (`making` with no progress yet), so the apps show "Converting to
     * Roman Urdu…" from the moment the transcript is there, also before the job has started.
     */
    public const STALE_AFTER_MINUTES = 20;

    /** The reason a "making" state that went quiet reads as failed. */
    public const TOOK_TOO_LONG = 'Converting took too long. Try again.';

    /** US$ per million input / output tokens of the models offered (Anthropic list prices, checked 2026-10-28). */
    private const PRICES = [
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-haiku-5-5' => [0.10, 0.50],
    ];

    public function __construct(private readonly TranscriptConverter $converter, private readonly CredentialVault $vault) {}

    /**
     * The views a transcript can have (plan A3): Urdu and mixed calls Roman Urdu, اردو (English words back in English
     * letters) and an English translation; English calls an Urdu translation; Arabic English and Urdu translations;
     * the other languages an English translation.
     *
     * @return list<string>
     */
    public static function available(Transcript $transcript): array
    {
        return match (self::sourceLanguage($transcript)) {
            'ur' => ['roman', 'urdu', 'english'],
            'en' => ['urdu'],
            'ar' => ['english', 'urdu'],
            default => ['english'],
        };
    }

    /** The language the views are made from: the transcript's, Urdu for an Auto one in Urdu letters, else English. */
    public static function sourceLanguage(Transcript $transcript): string
    {
        $language = LanguageChoice::normalize($transcript->language);
        if ($language !== null) {
            return $language;
        }

        return ScriptCheck::counts((string) $transcript->text)['arabic'] > 0 ? 'ur' : 'en';
    }

    /**
     * The views of a converted line that fail the checks, given its original [$source]: a missing or empty view, Hindi
     * letters, a marker lost or added, and for a transliteration (Roman Urdu, and اردو of an Urdu call) a word count more
     * than 25 % away from the original or, since v1.5 (Phase 1C), a word whose sound is not the original's
     * (App\Services\Views\SoundCheck). Pure; unit-tested.
     *
     * @param  array<string, string>  $out  view => text
     * @param  list<string>  $views
     * @return list<string>
     */
    public static function failures(string $source, array $out, array $views, string $language): array
    {
        $source = self::withoutAlternatives($source);
        $markers = preg_match_all(self::MARKER, $source);
        $words = self::words($source);
        $failed = [];
        foreach ($views as $view) {
            $text = trim((string) ($out[$view] ?? ''));
            $transliteration = $view === 'roman' || ($view === 'urdu' && $language === 'ur');
            $more = max(2, (int) round($words * self::WORD_SLACK));
            $difference = self::words($text) - $words;
            if ($text === ''
                || ScriptCheck::hasDevanagari($text)
                || preg_match_all(self::MARKER, $text) !== $markers
                || ($transliteration && $difference > $more)
                // Since v1.5 (Phase 1C): every word keeps its sound ("تعل" never becomes "dekh"); a dropped word fails
                // here too, while number words written as digits ("دو ہزار پانچ سو" → "2,500") may make fewer words.
                || ($transliteration && ! SoundCheck::matches($source, $text))) {
                $failed[] = $view;
            }
        }

        return $failed;
    }

    /**
     * "[?أفحم|probably أفهم]" (an unsure word with another engine's version, since v1.5 Phase 1C) as "[?أفحم]": what
     * the checks compare against, and what a view keeps if the model copied the alternative. Pure.
     */
    public static function withoutAlternatives(string $text): string
    {
        return preg_replace('/\[\?([^\]|]*)\|[^\]]*\]/u', '[?$1]', $text) ?? $text;
    }

    /** Roman Urdu, and اردو of an Urdu call: the same words in other letters (a translation may reword). */
    public static function isTransliteration(string $view, string $language): bool
    {
        return $view === 'roman' || ($view === 'urdu' && $language === 'ur');
    }

    /**
     * A transliteration that failed twice, mended word by word (since v1.5, Phase 1C): of the two answers the one with
     * fewer words off, with the words that sound right kept and each other word of the original in plain letters (Roman
     * Urdu) or as it is (اردو). Null when nothing usable came back or the mended line still fails (Hindi letters).
     *
     * @param  list<string|null>  $answers
     */
    public static function mended(string $source, array $answers, string $view, string $language): ?string
    {
        $source = self::withoutAlternatives($source);
        $best = null;
        $bestOff = PHP_INT_MAX;
        foreach ($answers as $answer) {
            if (! is_string($answer) || trim($answer) === '' || ScriptCheck::hasDevanagari($answer)) {
                continue;
            }
            $off = count(SoundCheck::failing($source, $answer)) + SoundCheck::added($source, $answer);
            if ($off < $bestOff) {
                [$best, $bestOff] = [$answer, $off];
            }
        }
        if ($best === null) {
            return null;
        }
        $plain = $view === 'roman' ? SoundCheck::plainRoman(...) : fn (string $word): string => $word;
        $mended = SoundCheck::repair($source, $best, $plain);

        return self::failures($source, [$view => $mended], [$view], $language) === [] ? $mended : null;
    }

    private static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Fingerprint of the lines the views were made from. */
    public static function fingerprint(Transcript $transcript): string
    {
        return sha1((string) json_encode(array_map(fn ($s): string => (string) ($s['text'] ?? ''), $transcript->segments ?? [])));
    }

    /**
     * What the apps show: `status` none | making | ready | failed | unavailable, and when ready each view as a list
     * aligned with the segments (null for an empty line).
     *
     * @return array{status: string, available: list<string>, roman: list<string|null>|null, english: list<string|null>|null, urdu: list<string|null>|null, default: string, model: string|null, made_at: string|null, error: string|null, progress: object|array<string, array{done: int, total: int}>, kept: object|array<string, list<int>>}
     */
    public function state(Transcript $transcript): array
    {
        $available = self::available($transcript);
        $views = $transcript->meta['views'] ?? null;
        $stored = self::storedStatus($transcript);
        // Since U19: the view it opens in that is about to be made counts as being made, and as failed once that ran
        // out (see expected).
        $status = $stored === 'none' ? (self::expected($transcript, $available) ?? 'none') : $stored;
        $ready = $status === 'ready';

        return [
            'status' => $status,
            'available' => $available,
            'roman' => $ready && in_array('roman', $available, true) ? self::aligned($views['roman'] ?? [], $transcript) : null,
            'english' => $ready && in_array('english', $available, true) ? self::aligned($views['english'] ?? [], $transcript) : null,
            'urdu' => $ready && in_array('urdu', $available, true) ? self::aligned($views['urdu'] ?? [], $transcript) : null,
            'default' => self::defaultView($available, self::sourceLanguage($transcript)),
            'model' => $ready ? ($views['model'] ?? null) : null,
            'made_at' => $ready ? ($views['made_at'] ?? null) : null,
            // As people may read it: a reason kept from before v1.8.1 may name the AI's maker.
            'error' => $status === 'failed' ? ProviderText::forPeople($stored === 'failed' && is_array($views) && is_string($views['error'] ?? null) ? $views['error'] : self::TOOK_TOO_LONG) : null,
            // Lines shown with their original words in a view because the view's text failed the checks twice.
            // Always a JSON object (view => line indexes), also when empty, so the apps can read it as a map.
            // Since v1.5 (Phase 1C): lines done of lines per view while the views are being made, {} otherwise.
            'progress' => self::progressOf($status, $views, $available),
            'kept' => $ready && is_array($views['kept'] ?? null) && $views['kept'] !== []
                ? array_map(fn ($l): array => array_values(array_map('intval', (array) $l)), $views['kept']) : (object) [],
        ];
    }

    /**
     * The views in a Library row (since v1.5, Phase 1C): whether they are being made and how far, from what is stored,
     * without reading the lines (a list may hold many recordings). Null when the transcript has no views to make.
     *
     * @return array{status: string, default: string, progress: object|array<string, array{done: int, total: int}>}|null
     */
    public static function rowSummary(?Transcript $transcript): ?array
    {
        if ($transcript === null || $transcript->status !== TranscriptStatus::Completed) {
            return null;
        }
        $available = self::available($transcript);
        if ($available === []) {
            return null;
        }
        $views = $transcript->meta['views'] ?? null;
        // Only this transcript's own views: ones made (or that failed) before the last edit are stale, as in state().
        $status = is_array($views) && self::storedFresh($transcript, $views) ? (string) ($views['status'] ?? 'none') : 'none';
        if ($status === 'making' && is_array($views) && self::isStale($views)) {
            $status = 'failed';
        }
        if ($status === 'none') {
            $status = self::expected($transcript, $available) ?? 'none';
        }

        return ['status' => $status, 'default' => self::defaultView($available, self::sourceLanguage($transcript)), 'progress' => self::progressOf($status, $views, $available)];
    }

    /**
     * Since 2.0.0-n (U19): a Library row's preview in the view the recording opens in (Roman Urdu for Urdu and mixed
     * calls) once that view is made, from what is stored (a row loads no lines): the view's lines in order, one a line,
     * at most [$length] characters. Null while the view is not made, was made before the last edit, or the recording
     * opens in its original words; the row then shows the transcript's own words.
     *
     * @return array{text: string, view: string}|null
     */
    public static function rowPreview(?Transcript $transcript, int $length = 160): ?array
    {
        if ($transcript === null || $transcript->status !== TranscriptStatus::Completed) {
            return null;
        }
        $views = $transcript->meta['views'] ?? null;
        $available = self::available($transcript);
        $default = self::defaultView($available, self::sourceLanguage($transcript));
        // The console's Library loads no meta, only the start of each view (CaptureSessionsTable::rowTranscriptColumns).
        if (! is_array($views) && $default !== 'original' && $transcript->getAttribute('views_status_head') !== null) {
            $views = self::fromHeads($transcript, $default);
        }
        if ($default === 'original' || ! is_array($views) || ($views['status'] ?? null) !== 'ready' || ! is_array($views[$default] ?? null)) {
            return null;
        }
        // Made before the last edit: stale (made again soon), so the edited words show meanwhile.
        if ($transcript->edited_at !== null && (! is_string($views['made_at'] ?? null) || $transcript->edited_at->gt($views['made_at']))) {
            return null;
        }
        $lines = $views[$default];
        ksort($lines, SORT_NUMERIC);
        $text = trim(implode("\n", array_filter(array_map(fn ($l): string => is_string($l) ? trim($l) : '', $lines), fn (string $l): bool => $l !== '')));

        return $text === '' ? null : ['text' => mb_strimwidth($text, 0, $length, '…'), 'view' => $default];
    }

    /** How much of each view a console Library row reads for its snippet (characters of the stored JSON). */
    public const ROW_HEAD_CHARS = 1200;

    /**
     * The views' state, when made and the first lines of [$view] from a row's heads (JSON_EXTRACT of the stored meta,
     * the view's list or object cut after ROW_HEAD_CHARS): the lines that arrived whole.
     *
     * @return array<string, mixed>
     */
    private static function fromHeads(Transcript $transcript, string $view): array
    {
        $unquote = fn (mixed $v): ?string => is_string($v) ? (string) (json_decode($v) ?? trim($v, '"')) : null;
        $lines = [];
        $head = ltrim((string) $transcript->getAttribute("views_{$view}_head"));
        // Stored as a list when every line has words ("0", "1", … in order), else as an object of line => text.
        $pattern = str_starts_with($head, '[') ? '/()("(?:[^"\\\\]|\\\\.)*")/u' : '/"(\d+)"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/u';
        preg_match_all($pattern, $head, $m, PREG_SET_ORDER);
        foreach ($m as $n => [, $key, $text]) {
            $decoded = json_decode($text);
            if (is_string($decoded)) {
                $lines[$key === '' ? $n : (int) $key] = $decoded;
            }
        }

        return ['status' => $unquote($transcript->getAttribute('views_status_head')), 'made_at' => $unquote($transcript->getAttribute('views_made_at_head')), $view => $lines];
    }

    /**
     * The views' state as stored (none | making | ready | failed | unavailable): `none` when they were never made or an
     * edit made them stale, and since U19 `failed` for a "making" state that went quiet (isStale). What request() goes by.
     */
    public static function storedStatus(Transcript $transcript): string
    {
        if (self::available($transcript) === [] || $transcript->status !== TranscriptStatus::Completed) {
            return 'unavailable';
        }
        $views = $transcript->meta['views'] ?? null;
        if (! is_array($views) || ($views['fingerprint'] ?? null) !== self::fingerprint($transcript)) {
            return 'none';
        }
        $status = (string) ($views['status'] ?? 'none');

        return $status === 'making' && self::isStale($views) ? 'failed' : $status;
    }

    /**
     * Whether a "making" state moved no line for STALE_AFTER_MINUTES (since it started, or since its last line).
     *
     * @param  array<string, mixed>  $views
     */
    private static function isStale(array $views): bool
    {
        $last = $views['touched_at'] ?? $views['started_at'] ?? null;

        return is_string($last) && now()->subMinutes(self::STALE_AFTER_MINUTES)->gt($last);
    }

    /** How long after the transcript a view that never came reads as failed (Try again); older ones read as none. */
    public const RAN_OUT_HOURS = 24;

    /**
     * Since U19: what the view [$transcript] opens in (Roman Urdu for Urdu and mixed calls) is when nothing is stored
     * yet, although it opens in a view, a key is set and the plan has views (so autoMake makes it; a short note first
     * goes through ShortNoteCheckJob): `making` for STALE_AFTER_MINUTES after the final transcript (or a new version)
     * was finished or edited, so the apps show "Converting to Roman Urdu…" from the first moment; then `failed` (it
     * never started: "Converting took too long. Try again.") until RAN_OUT_HOURS; null otherwise (none).
     *
     * @param  list<string>  $available
     */
    public static function expected(Transcript $transcript, array $available): ?string
    {
        if ($available === [] || $transcript->status !== TranscriptStatus::Completed
            || ! in_array($transcript->kind, [TranscriptKind::Final, TranscriptKind::Alternate], true)) {
            return null;
        }
        // A Library row reads no meta when views are stored (has_views, CaptureSessionsTable): they are not on their way.
        if (! is_array($transcript->meta['views'] ?? null) && (int) $transcript->getAttribute('has_views') === 1) {
            return null;
        }
        $at = collect([$transcript->completed_at, $transcript->edited_at])->filter()->max();
        if ($at === null || now()->subHours(self::RAN_OUT_HOURS)->gt($at)
            || self::defaultView($available, self::sourceLanguage($transcript)) === 'original'
            || ! app(self::class)->configured() || ! self::entitled($transcript)) {
            return null;
        }

        return now()->subMinutes(self::STALE_AFTER_MINUTES)->gt($at) ? 'failed' : 'making';
    }

    /**
     * Whether the stored [$views] are this transcript's own, as a Library row can tell without its lines (the same with
     * them, so a row and the full recording agree): made, failed or last moved after the last edit.
     *
     * @param  array<string, mixed>  $views
     */
    private static function storedFresh(Transcript $transcript, array $views): bool
    {
        if ($transcript->edited_at === null) {
            return true;
        }
        $at = $views['failed_at'] ?? $views['made_at'] ?? $views['touched_at'] ?? $views['started_at'] ?? null;

        return is_string($at) && ! $transcript->edited_at->gt($at);
    }

    /**
     * `{"roman": {"done": 12, "total": 20}, …}` while the views are being made (one entry per view, lines done of
     * lines), an empty object otherwise, so the apps can read it as a map.
     *
     * @param  list<string>  $available
     * @return object|array<string, array{done: int, total: int}>
     */
    private static function progressOf(string $status, mixed $views, array $available): object|array
    {
        if ($status !== 'making' || ! is_array($views)) {
            return (object) [];
        }
        $total = max(0, (int) ($views['lines_total'] ?? 0));
        if ($total === 0) {
            return (object) [];
        }
        $done = max(0, min($total, (int) ($views['lines_done'] ?? 0)));

        return array_combine($available, array_fill(0, count($available), ['done' => $done, 'total' => $total]));
    }

    /** Keeps how many lines are done while the views are made, without touching anything else of the making state. */
    private function rememberProgress(Transcript $transcript, int $done, int $total): void
    {
        $fresh = Transcript::query()->find($transcript->id);
        $views = $fresh?->meta['views'] ?? null;
        if ($fresh === null || ! is_array($views) || ($views['status'] ?? null) !== 'making') {
            return;
        }
        $fresh->forceFill(['meta' => [...($fresh->meta ?? []), 'views' => [...$views, 'lines_done' => $done, 'lines_total' => $total, 'touched_at' => now()->toAtomString()]]])->saveQuietly();
    }

    /**
     * The view the apps open a transcript in (Settings → Transcription: per language, Roman Urdu for Urdu and mixed
     * calls, the original otherwise), when this transcript has it.
     *
     * @param  list<string>  $available
     */
    public static function defaultView(array $available, string $language): string
    {
        $key = self::DEFAULT_SETTINGS[$language] ?? null;
        $default = $key === null ? 'original' : app(SettingsRepository::class)->get($key, $language === 'ur' ? 'roman' : 'original');

        return is_string($default) && in_array($default, $available, true) ? $default : 'original';
    }

    /**
     * Makes the views of a finished transcript at once when its language opens in one (Roman Urdu for Urdu calls by
     * default) and a key is set: after the final transcript, a re-transcription or an edit. Never throws.
     */
    public function autoMake(Transcript $transcript): void
    {
        if (! $this->configured() || $transcript->status !== TranscriptStatus::Completed || ! self::entitled($transcript)) {
            return;
        }
        $available = self::available($transcript);
        if (self::defaultView($available, self::sourceLanguage($transcript)) === 'original') {
            return;
        }
        try {
            $this->request($transcript);
        } catch (Throwable $e) {
            Log::info('Show as not started', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * Said in every app when the AI key is missing, with where to add it: in plain words, never which provider (since
     * v1.8.1, D-129); API Credentials names it for the person who runs the server.
     */
    public static function missingKeyMessage(): string
    {
        return 'Roman Urdu and English views are not set up on this server yet. Whoever runs it adds the AI key under API Credentials: '.rtrim((string) config('app.url'), '/').'/admin/api-credentials';
    }

    /** Whether views can be made at all now (a key is set; the vault keeps it once a request or a queued job). */
    public function configured(): bool
    {
        $key = $this->vault->get('anthropic', 'api_key');

        return $key !== null && $key !== '';
    }

    /**
     * Starts making the views unless they are ready or being made. Throws when the transcript has none or no key is
     * set.
     *
     * @return array<string, mixed> the state after the request
     */
    public function request(Transcript $transcript): array
    {
        $stored = self::storedStatus($transcript);
        if ($stored === 'unavailable') {
            throw new RuntimeException('This transcript has no Show as view yet: it is not finished.');
        }
        // As stored: a view about to be made, or that never came (expected), is started here.
        if (in_array($stored, ['ready', 'making'], true)) {
            return $this->state($transcript);
        }
        // Since v1.6 the views are a paid feature (403 feature_not_in_plan with the plan that has them).
        if (! self::entitled($transcript)) {
            throw Entitlements::locked('views');
        }
        if (! $this->configured()) {
            throw new RuntimeException(self::missingKeyMessage());
        }
        $this->remember($transcript, ['status' => 'making', 'fingerprint' => self::fingerprint($transcript), 'started_at' => now()->toAtomString()]);
        // Since v1.5: a note of a minute or less gets its views on the fast path, not after the next queue run.
        FastPath::dispatch(new MakeTranscriptViewsJob($transcript->id), FastPath::isShortTranscript($transcript));

        return $this->state($transcript->refresh());
    }

    /** Whether the person whose recording it is may have views made (since v1.6, the plan's `views`). */
    public static function entitled(Transcript $transcript): bool
    {
        // Once per transcript as loaded (a Library row asks several times for the one it shows); a transcript read again
        // (the next request, the next poll) asks again, so a plan that changed meanwhile counts.
        self::$entitled ??= new WeakMap;
        if (! isset(self::$entitled[$transcript])) {
            $session = CaptureSession::acrossPeople()->withTrashed()->find($transcript->capture_session_id, ['id', 'workspace_id', 'user_id']);
            self::$entitled[$transcript] = $session instanceof CaptureSession && app(PlanLimits::class)->allows($session->workspace_id, 'views', $session->user_id);
        }

        return self::$entitled[$transcript];
    }

    /** @var WeakMap<Transcript, bool>|null whether each loaded transcript's plan has views (entitled) */
    private static ?WeakMap $entitled = null;

    /** Makes the views (on the queue) and stores them with the transcript, or the reason they failed. */
    public function make(Transcript $transcript): void
    {
        $available = self::available($transcript);
        $fingerprint = self::fingerprint($transcript);
        $lines = [];
        // Unsure words go in as "[?word]", so every view keeps them on the same word (plan A4).
        $threshold = UnsureMarkers::settings()['threshold'];
        foreach ($transcript->segments ?? [] as $i => $segment) {
            $text = UnsureMarkers::markForViews($segment, $threshold);
            if ($text !== '') {
                $lines[(int) $i] = $text;
            }
        }
        // Since v1.5 (Phase 1C): a note of 3 minutes or less may use a cheaper model (Settings → Recording, off until
        // measured as good as the main one); the main model for everything else.
        $short = app(SettingsRepository::class)->get('views.short_model');
        if ($this->converter instanceof ClaudeTranscriptConverter) {
            $this->converter->modelForThisTranscript = is_string($short) && $short !== '' && ShortNoteCheck::lengthMs($transcript) <= ShortNoteCheck::MAX_MS ? $short : null;
        }
        $tokens = ['input' => 0, 'output' => 0];
        $model = null;
        $result = [];
        $kept = [];
        $mendedLines = [];
        $misheard = [];
        $language = self::sourceLanguage($transcript);
        $linesDone = 0;
        $this->rememberProgress($transcript, 0, count($lines));
        try {
            foreach (self::chunks($lines) as $chunk) {
                $answer = $this->convertAll($chunk, $language, $available, $tokens, $model, $misheard);
                // Lines that fail the checks are asked once more (Roman Urdu word by word, since v1.5); a view that fails
                // again keeps the original words.
                $bad = array_filter($chunk, fn (string $text, int $i): bool => self::failures($text, $answer[$i] ?? [], $available, $language) !== [], ARRAY_FILTER_USE_BOTH);
                $again = $bad === [] ? [] : $this->convertAll($bad, $language, $available, $tokens, $model, $misheard);
                $romanAgainBad = array_filter(
                    $bad,
                    fn (string $text, int $i): bool => in_array('roman', self::failures($text, $answer[$i] ?? [], $available, $language), true)
                        && self::failures($text, ['roman' => (string) ($again[$i]['roman'] ?? '')], ['roman'], $language) !== [],
                    ARRAY_FILTER_USE_BOTH
                );
                $byWord = $this->romanByWord($romanAgainBad, $language, $tokens, $model);
                foreach ($bad as $i => $text) {
                    foreach (self::failures($text, $answer[$i] ?? [], $available, $language) as $view) {
                        $retry = $view === 'roman' && isset($byWord[$i]) ? $byWord[$i] : ($again[$i][$view] ?? null);
                        $mended = null;
                        if (is_string($retry) && self::failures($text, [$view => $retry], [$view], $language) === []) {
                            $answer[$i][$view] = $retry;
                        } elseif (self::isTransliteration($view, $language)
                            && ($mended = self::mended($text, [$answer[$i][$view] ?? null, is_string($retry) ? $retry : null], $view, $language)) !== null) {
                            // Since v1.5 (Phase 1C): the words that sound right stay, the others are the original's.
                            $answer[$i][$view] = $mended;
                            $mendedLines[$view][] = $i;
                        } else {
                            unset($answer[$i][$view]);
                            $kept[$view][] = $i;
                        }
                    }
                }
                foreach ($answer as $i => $view) {
                    $result[$i] = array_map(self::withoutAlternatives(...), $view);
                }
                $linesDone += count($chunk);
                $this->rememberProgress($transcript, $linesDone, count($lines));
            }
        } catch (Throwable $e) {
            Log::warning('Show as failed', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->remember($transcript, ['status' => 'failed', 'fingerprint' => $fingerprint, 'failed_at' => now()->toAtomString(), 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The views could not be made.']);
            $this->bill($transcript, $model, $tokens);

            return;
        }
        $views = ['status' => 'ready', 'fingerprint' => $fingerprint, 'model' => $model, 'made_at' => now()->toAtomString(), 'kept' => $kept, 'mended' => $mendedLines];
        foreach ($available as $view) {
            $views[$view] = [];
            foreach ($lines as $i => $text) {
                // A line Claude left out keeps its own words, so no line goes missing from a view.
                $views[$view][(string) $i] = $result[$i][$view] ?? $text;
            }
        }
        $this->remember($transcript, $views);
        // Since U19: an automatic title in Urdu letters is written again in Roman Urdu once the Roman view is in.
        app(AutoTitles::class)->afterViews($transcript);
        $this->suggestMisheard($transcript, $misheard);
        $this->bill($transcript, $model, $tokens);
    }

    /** Open suggested fixes from one run of the views at most (the model's "probably misheard"). */
    public const MAX_VIEW_SUGGESTIONS = 20;

    /**
     * The words the model believes were misheard become suggested fixes (`reason: "view_check"`, since v1.5, Phase 1C):
     * nothing changes until a person accepts one. Those of an earlier run of the views that are still open are put aside
     * (the lines may have changed since); ids stay what they were.
     *
     * @param  array<int, list<array{from: string, to: string}>>  $misheard
     */
    private function suggestMisheard(Transcript $transcript, array $misheard): void
    {
        $fresh = Transcript::query()->find($transcript->id);
        if ($fresh === null) {
            return;
        }
        $suggestions = $fresh->meta['suggestions'] ?? [];
        $suggestions = is_array($suggestions) ? $suggestions : [];
        $changed = false;
        foreach ($suggestions as $n => $s) {
            if (is_array($s) && ($s['reason'] ?? null) === 'view_check' && ($s['status'] ?? 'open') === 'open') {
                $suggestions[$n]['status'] = 'stale';
                $changed = true;
            }
        }
        $segments = $fresh->segments ?? [];
        $added = 0;
        $seen = [];
        foreach ($misheard as $line => $words) {
            foreach ($words as $word) {
                $from = mb_substr(trim($word['from']), 0, 100);
                $to = mb_substr(trim($word['to']), 0, 100);
                $key = $line."\0".$from;
                if ($added >= self::MAX_VIEW_SUGGESTIONS || $from === '' || $to === '' || $from === $to || isset($seen[$key])
                    || ! str_contains((string) ($segments[$line]['text'] ?? ''), $from)) {
                    continue;
                }
                $seen[$key] = true;
                $suggestions[] = ['line' => (int) $line, 'from' => $from, 'to' => $to, 'reason' => 'view_check', 'status' => 'open'];
                $added++;
                $changed = true;
            }
        }
        if ($changed) {
            $fresh->forceFill(['meta' => [...($fresh->meta ?? []), 'suggestions' => $suggestions]])->save();
            $transcript->setRawAttributes($fresh->getAttributes(), true);
        }
    }

    /**
     * Roman Urdu word by word (since v1.5) for lines whose Roman Urdu lost words twice: one transliteration per word,
     * kept only when there is exactly one per word and none is empty. Converters without the word-by-word mode, an
     * empty list or a failed request give nothing back (the lines then keep their original words).
     *
     * @param  array<int, string>  $lines
     * @param  array{input: int, output: int}  $tokens
     * @return array<int, string>
     */
    private function romanByWord(array $lines, string $language, array &$tokens, ?string &$model): array
    {
        if ($lines === [] || ! $this->converter instanceof WordByWordConverter) {
            return [];
        }
        $words = array_map(self::wordList(...), $lines);
        try {
            $answer = $this->converter->romanWords($words, $language);
        } catch (Throwable $e) {
            Log::info('Roman Urdu word by word failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return [];
        }
        $tokens['input'] += $answer['input_tokens'];
        $tokens['output'] += $answer['output_tokens'];
        $model = $answer['model'];
        $out = [];
        foreach ($answer['lines'] as $i => $roman) {
            if (isset($words[$i]) && count($roman) === count($words[$i]) && ! in_array('', $roman, true)) {
                $out[$i] = implode(' ', $roman);
            }
        }

        return $out;
    }

    /**
     * The words of a line for the word-by-word mode; a marker ("[unclear: two words]", "[?word]") stays one word.
     *
     * @return list<string>
     */
    public static function wordList(string $text): array
    {
        preg_match_all('/\[[^\]]*\]\S*|\S+/u', trim($text), $m);

        return $m[0];
    }

    /**
     * One chunk, split in two when its answer does not fit in one request.
     *
     * @param  array<int, string>  $chunk
     * @param  array{input: int, output: int}  $tokens
     * @param  list<string>  $views
     * @param  array<int, list<array{from: string, to: string}>>  $misheard  the words the model believes were misheard, per line
     * @return array<int, array<string, string>>
     */
    private function convertAll(array $chunk, string $language, array $views, array &$tokens, ?string &$model, array &$misheard): array
    {
        try {
            $answer = $this->converter->convert($chunk, $language, $views);
        } catch (TooLongForOneRequest $e) {
            if (count($chunk) < 2) {
                throw $e;
            }
            $half = (int) ceil(count($chunk) / 2);

            return array_replace(
                $this->convertAll(array_slice($chunk, 0, $half, true), $language, $views, $tokens, $model, $misheard),
                $this->convertAll(array_slice($chunk, $half, null, true), $language, $views, $tokens, $model, $misheard),
            );
        }
        $tokens['input'] += $answer['input_tokens'];
        $tokens['output'] += $answer['output_tokens'];
        $model = $answer['model'];
        foreach ($answer['misheard'] ?? [] as $line => $words) {
            foreach ($words as $word) {
                $misheard[(int) $line][] = $word;
            }
        }

        return $answer['lines'];
    }

    /**
     * Lines in order, cut into chunks of about [CHUNK_CHARS] characters.
     *
     * @param  array<int, string>  $lines
     * @return list<array<int, string>>
     */
    public static function chunks(array $lines): array
    {
        $chunks = [];
        $current = [];
        $size = 0;
        foreach ($lines as $i => $text) {
            $length = mb_strlen($text);
            if ($current !== [] && $size + $length > self::CHUNK_CHARS) {
                $chunks[] = $current;
                $current = [];
                $size = 0;
            }
            $current[$i] = $text;
            $size += $length;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** The view [$transcript] is shown in: its default view once it is made, else null (the original words). */
    public function shownView(Transcript $transcript): ?string
    {
        $state = $this->state($transcript);

        return $state['status'] === 'ready' && $state['default'] !== 'original' && is_array($state[$state['default']] ?? null) ? $state['default'] : null;
    }

    /**
     * The view's text for each segment (aligned), or null when that view is not ready.
     *
     * @return list<string|null>|null
     */
    public function textsFor(Transcript $transcript, string $view): ?array
    {
        $state = $this->state($transcript);

        return $state['status'] === 'ready' ? ($state[$view] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $views
     */
    private function remember(Transcript $transcript, array $views): void
    {
        $fresh = Transcript::query()->find($transcript->id);
        if ($fresh === null) {
            return;
        }
        $fill = ['meta' => [...($fresh->meta ?? []), 'views' => $views]];
        // Ready views are searchable too: Roman Urdu and Urdu letters find the call (folded like the transcript).
        if (($views['status'] ?? null) === 'ready') {
            $texts = [(string) $fresh->text];
            foreach (array_keys(self::VIEWS) as $view) {
                $texts[] = implode("\n", array_filter((array) ($views[$view] ?? []), 'is_string'));
            }
            $fill['search_text'] = UrduText::fold(implode("\n", array_filter($texts)));
        }
        $fresh->forceFill($fill)->save();
        $transcript->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * One usage row per run (token-priced; no audio minutes).
     *
     * @param  array{input: int, output: int}  $tokens
     */
    private function bill(Transcript $transcript, ?string $model, array $tokens): void
    {
        if ($tokens['input'] + $tokens['output'] === 0) {
            return;
        }
        $session = CaptureSession::withTrashed()->find($transcript->capture_session_id);
        if ($session === null) {
            return;
        }
        [$in, $out] = self::PRICES[$model ?? ''] ?? self::PRICES['claude-opus-5-5'];
        $cost = round(($tokens['input'] * $in + $tokens['output'] * $out) / 1_000_000, 6);
        UsageLedgerEntry::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'device_id' => $session->device_id,
            'capture_session_id' => $session->id,
            'transcript_id' => $transcript->id,
            'report_id' => 'views/'.$transcript->id.'/'.now()->format('YmdHis'),
            'source' => UsageLedgerEntry::SOURCE_SERVER,
            'use_case' => 'show_as',
            'provider' => 'claude',
            'duration_ms' => 0,
            'channels' => 1,
            'billed_ms' => 0,
            'input_tokens' => $tokens['input'],
            'output_tokens' => $tokens['output'],
            'unit_price_usd' => 0,
            'cost_usd' => $cost,
            'billable' => true,
            'model' => $model !== null ? mb_substr($model, 0, 64) : null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param  mixed  $map  segment index => text (stored with string keys)
     * @return list<string|null>
     */
    private static function aligned(mixed $map, Transcript $transcript): array
    {
        $map = is_array($map) ? $map : [];
        $out = [];
        foreach (array_keys($transcript->segments ?? []) as $i) {
            $text = $map[(string) $i] ?? $map[$i] ?? null;
            $out[] = is_string($text) ? $text : null;
        }

        return $out;
    }
}
