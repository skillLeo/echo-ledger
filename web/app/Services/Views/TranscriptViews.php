<?php

declare(strict_types=1);

namespace App\Services\Views;

use App\Enums\TranscriptStatus;
use App\Jobs\MakeTranscriptViewsJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\LanguageChoice;
use App\Stt\ScriptCheck;
use App\Support\UnsureMarkers;
use App\Support\UrduText;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

    /** Settings key of the view each language opens in, and its default (Roman Urdu for Urdu, the original otherwise). */
    public const DEFAULT_SETTINGS = ['ur' => 'views.default_ur', 'en' => 'views.default_en', 'ar' => 'views.default_ar'];

    /** "[unclear]", "[unclear: …]" and "[?word]" (a word the engine was unsure of): kept on the same word in every view. */
    private const MARKER = '/\[(?:unclear(?::[^\]]*)?|\?[^\]]+)\]/u';

    /** A transliteration may differ from the original by this share of its words (and always by 2). */
    private const WORD_SLACK = 0.25;

    /** Source characters per request; a chunk whose answer does not fit is split again. */
    public const CHUNK_CHARS = 6000;

    /** A "making" state older than this is treated as lost and may be started again. */
    private const STALE_AFTER_MINUTES = 20;

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
     * than 25 % away from the original. Pure; unit-tested.
     *
     * @param  array<string, string>  $out  view => text
     * @param  list<string>  $views
     * @return list<string>
     */
    public static function failures(string $source, array $out, array $views, string $language): array
    {
        $markers = preg_match_all(self::MARKER, $source);
        $words = self::words($source);
        $failed = [];
        foreach ($views as $view) {
            $text = trim((string) ($out[$view] ?? ''));
            $transliteration = $view === 'roman' || ($view === 'urdu' && $language === 'ur');
            $slack = max(2, (int) round($words * self::WORD_SLACK));
            if ($text === ''
                || ScriptCheck::hasDevanagari($text)
                || preg_match_all(self::MARKER, $text) !== $markers
                || ($transliteration && abs(self::words($text) - $words) > $slack)) {
                $failed[] = $view;
            }
        }

        return $failed;
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
     * @return array{status: string, available: list<string>, roman: list<string|null>|null, english: list<string|null>|null, urdu: list<string|null>|null, default: string, model: string|null, made_at: string|null, error: string|null}
     */
    public function state(Transcript $transcript): array
    {
        $available = self::available($transcript);
        $views = $transcript->meta['views'] ?? null;
        $fresh = is_array($views) && ($views['fingerprint'] ?? null) === self::fingerprint($transcript);
        $status = match (true) {
            $available === [] || $transcript->status !== TranscriptStatus::Completed => 'unavailable',
            ! $fresh => 'none',
            default => (string) ($views['status'] ?? 'none'),
        };
        if ($status === 'making' && isset($views['started_at']) && now()->subMinutes(self::STALE_AFTER_MINUTES)->gt($views['started_at'])) {
            $status = 'none';
        }
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
            'error' => $status === 'failed' ? ($views['error'] ?? null) : null,
            // Lines shown with their original words in a view because the view's text failed the checks twice.
            // Always a JSON object (view => line indexes), also when empty, so the apps can read it as a map.
            'kept' => $ready && is_array($views['kept'] ?? null) && $views['kept'] !== []
                ? array_map(fn ($l): array => array_values(array_map('intval', (array) $l)), $views['kept']) : (object) [],
        ];
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
        if (! $this->configured() || $transcript->status !== TranscriptStatus::Completed) {
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

    /** Said in every app when the Anthropic key is missing, with where to add it. */
    public static function missingKeyMessage(): string
    {
        return 'Add your Anthropic key in API Credentials to see Roman Urdu and English: '.rtrim((string) config('app.url'), '/').'/admin/api-credentials';
    }

    /** Whether views can be made at all now (a key is set). */
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
        $state = $this->state($transcript);
        if ($state['status'] === 'unavailable') {
            throw new RuntimeException('This transcript has no Show as view yet: it is not finished.');
        }
        if (! $this->configured()) {
            throw new RuntimeException(self::missingKeyMessage());
        }
        if (in_array($state['status'], ['ready', 'making'], true)) {
            return $state;
        }
        $this->remember($transcript, ['status' => 'making', 'fingerprint' => self::fingerprint($transcript), 'started_at' => now()->toAtomString()]);
        MakeTranscriptViewsJob::dispatch($transcript->id);

        return $this->state($transcript->refresh());
    }

    /** Makes the views (on the queue) and stores them with the transcript, or the reason they failed. */
    public function make(Transcript $transcript): void
    {
        $available = self::available($transcript);
        $fingerprint = self::fingerprint($transcript);
        $lines = [];
        // Unsure words go in as "[?word]", so every view keeps them on the same word (plan A4).
        $threshold = UnsureMarkers::settings()['threshold'];
        foreach ($transcript->segments ?? [] as $i => $segment) {
            $text = UnsureMarkers::mark($segment, $threshold);
            if ($text !== '') {
                $lines[(int) $i] = $text;
            }
        }
        $tokens = ['input' => 0, 'output' => 0];
        $model = null;
        $result = [];
        $kept = [];
        $language = self::sourceLanguage($transcript);
        try {
            foreach (self::chunks($lines) as $chunk) {
                $answer = $this->convertAll($chunk, $language, $available, $tokens, $model);
                // Lines that fail the checks are asked once more; a view that fails again keeps the original words.
                $bad = array_filter($chunk, fn (string $text, int $i): bool => self::failures($text, $answer[$i] ?? [], $available, $language) !== [], ARRAY_FILTER_USE_BOTH);
                $again = $bad === [] ? [] : $this->convertAll($bad, $language, $available, $tokens, $model);
                foreach ($bad as $i => $text) {
                    foreach (self::failures($text, $answer[$i] ?? [], $available, $language) as $view) {
                        $retry = $again[$i][$view] ?? null;
                        if (is_string($retry) && self::failures($text, [$view => $retry], [$view], $language) === []) {
                            $answer[$i][$view] = $retry;
                        } else {
                            unset($answer[$i][$view]);
                            $kept[$view][] = $i;
                        }
                    }
                }
                foreach ($answer as $i => $view) {
                    $result[$i] = $view;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Show as failed', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->remember($transcript, ['status' => 'failed', 'fingerprint' => $fingerprint, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The views could not be made.']);
            $this->bill($transcript, $model, $tokens);

            return;
        }
        $views = ['status' => 'ready', 'fingerprint' => $fingerprint, 'model' => $model, 'made_at' => now()->toAtomString(), 'kept' => $kept];
        foreach ($available as $view) {
            $views[$view] = [];
            foreach ($lines as $i => $text) {
                // A line Claude left out keeps its own words, so no line goes missing from a view.
                $views[$view][(string) $i] = $result[$i][$view] ?? $text;
            }
        }
        $this->remember($transcript, $views);
        $this->bill($transcript, $model, $tokens);
    }

    /**
     * One chunk, split in two when its answer does not fit in one request.
     *
     * @param  array<int, string>  $chunk
     * @param  array{input: int, output: int}  $tokens
     * @param  list<string>  $views
     * @return array<int, array<string, string>>
     */
    private function convertAll(array $chunk, string $language, array $views, array &$tokens, ?string &$model): array
    {
        try {
            $answer = $this->converter->convert($chunk, $language, $views);
        } catch (TooLongForOneRequest $e) {
            if (count($chunk) < 2) {
                throw $e;
            }
            $half = (int) ceil(count($chunk) / 2);

            return array_replace(
                $this->convertAll(array_slice($chunk, 0, $half, true), $language, $views, $tokens, $model),
                $this->convertAll(array_slice($chunk, $half, null, true), $language, $views, $tokens, $model),
            );
        }
        $tokens['input'] += $answer['input_tokens'];
        $tokens['output'] += $answer['output_tokens'];
        $model = $answer['model'];

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
