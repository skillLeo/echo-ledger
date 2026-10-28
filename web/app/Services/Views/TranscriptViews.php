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
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * "Show as" (since v1.2): Roman Urdu, English and Urdu reading views of a finished transcript, made once with the Claude API
 * on the queue and kept with the transcript (`meta.views`). They are labelled as views everywhere; the transcript
 * itself never changes. An edit to the transcript makes them stale: they are made again on the next request.
 */
final class TranscriptViews
{
    /** view => label */
    public const VIEWS = ['roman' => 'Roman Urdu', 'english' => 'English', 'urdu' => 'Urdu'];

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
     * The views a transcript can have: Roman Urdu and English for Urdu, Roman Urdu and Urdu (translations) for
     * English, English for Arabic and the other languages.
     *
     * @return list<string>
     */
    public static function available(Transcript $transcript): array
    {
        $language = LanguageChoice::normalize($transcript->language);
        $arabicScript = ScriptCheck::counts((string) $transcript->text)['arabic'] > 0;

        return match (true) {
            $language === 'ur' || ($language === null && $arabicScript) => ['roman', 'english'],
            $language === 'en' || $language === null => ['roman', 'urdu'],
            default => ['english'],
        };
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
            'default' => self::defaultView($available),
            'model' => $ready ? ($views['model'] ?? null) : null,
            'made_at' => $ready ? ($views['made_at'] ?? null) : null,
            'error' => $status === 'failed' ? ($views['error'] ?? null) : null,
        ];
    }

    /**
     * The view the apps open a transcript in (Settings → Show as, Original by default), when this transcript has it.
     *
     * @param  list<string>  $available
     */
    public static function defaultView(array $available): string
    {
        $default = app(SettingsRepository::class)->get('views.default', 'original');

        return is_string($default) && in_array($default, $available, true) ? $default : 'original';
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
            throw new RuntimeException('Add the Anthropic API key on API Credentials to make the Show as views.');
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
        foreach ($transcript->segments ?? [] as $i => $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text !== '') {
                $lines[(int) $i] = $text;
            }
        }
        $tokens = ['input' => 0, 'output' => 0];
        $model = null;
        $result = [];
        try {
            foreach (self::chunks($lines) as $chunk) {
                foreach ($this->convertAll($chunk, (string) (LanguageChoice::normalize($transcript->language) ?? 'en'), $available, $tokens, $model) as $i => $view) {
                    $result[$i] = $view;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Show as failed', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            $this->remember($transcript, ['status' => 'failed', 'fingerprint' => $fingerprint, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'The views could not be made.']);
            $this->bill($transcript, $model, $tokens);

            return;
        }
        $views = ['status' => 'ready', 'fingerprint' => $fingerprint, 'model' => $model, 'made_at' => now()->toAtomString()];
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
        $fresh->forceFill(['meta' => [...($fresh->meta ?? []), 'views' => $views]])->save();
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
