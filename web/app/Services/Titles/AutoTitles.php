<?php

declare(strict_types=1);

namespace App\Services\Titles;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\MakeAutoTitleJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\Views\TranscriptViews;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Automatic titles (since v1.5, Phase 1C; docs/API.md "Easy, trustworthy, clear"): when a recording's final transcript
 * is ready and nobody gave it a title, it gets a short one from what was said (Claude Haiku; the first words when no
 * key is set), marked `title_auto`. A title set by a person is never replaced. A call whose caller or meeting name is
 * known keeps showing that name ("Call with Omar Khan"), so it gets none.
 */
final class AutoTitles
{
    public const MAX = 60;

    /** Words the first-words title takes at most. */
    private const FIRST_WORDS = 8;

    public function __construct(private readonly TitleWriter $writer, private readonly CredentialVault $vault) {}

    /** Whether [$session] has no title of its own and nothing better to show. */
    public static function wanted(CaptureSession $session): bool
    {
        return ($session->title === null || trim($session->title) === '') && ($session->caller_name === null || trim($session->caller_name) === '');
    }

    /** Queues the title of [$transcript]'s recording when it is a finished final transcript of an untitled one. Never throws. */
    public function afterTranscript(Transcript $transcript): void
    {
        try {
            if ($transcript->kind !== TranscriptKind::Final || $transcript->status !== TranscriptStatus::Completed) {
                return;
            }
            $session = CaptureSession::withTrashed()->withoutGlobalScopes()->find($transcript->capture_session_id);
            if ($session !== null && self::wanted($session)) {
                MakeAutoTitleJob::dispatch($session->id);
            }
        } catch (Throwable $e) {
            Log::info('Automatic title not started', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /** A title cleared by a person: the automatic one is asked for again when the transcript is ready. Never throws. */
    public function ask(CaptureSession $session): void
    {
        $final = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final->value)->first();
        if ($final !== null) {
            $this->afterTranscript($final);
        }
    }

    /** Writes the title (on the queue). Leaves a recording that got a title in the meantime alone. */
    public function make(CaptureSession $session): void
    {
        $final = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final->value)
            ->where('status', TranscriptStatus::Completed->value)->first();
        $text = $final === null ? '' : self::plainText($final);
        if ($text === '' || ! self::wanted($session)) {
            return;
        }
        $title = null;
        if ($this->configured()) {
            try {
                $answer = $this->writer->title($text, TranscriptViews::sourceLanguage($final));
                $title = self::clean($answer['title']);
                $this->bill($session, $answer);
            } catch (Throwable $e) {
                Log::info('Automatic title fell back to the first words', ['session' => $session->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
        $title = $title !== null && $title !== '' ? $title : self::firstWords($text);
        if ($title === '') {
            return;
        }
        // Only while still untitled: a person who named it meanwhile wins.
        $saved = CaptureSession::withTrashed()->withoutGlobalScopes()->whereKey($session->id)
            ->where(fn ($q) => $q->whereNull('title')->orWhere('title', ''))
            ->update(['title' => $title, 'title_auto' => true, 'updated_at' => now()]);
        if ($saved > 0) {
            $session->forceFill(['title' => $title, 'title_auto' => true]);
        }
    }

    /** The transcript's words without speakers or markers. */
    public static function plainText(Transcript $transcript): string
    {
        $lines = array_map(fn (array $s): string => (string) ($s['text'] ?? ''), $transcript->segments ?? []);
        $text = implode(' ', $lines !== [] ? $lines : [(string) $transcript->text]);
        $text = (string) preg_replace('/\[(?:unclear(?::[^\]]*)?)\]/u', ' ', $text);
        $text = (string) preg_replace('/\[\?([^\]]+)\]/u', '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** One line, no quotes or closing full stop, at most 60 characters cut at a word. */
    public static function clean(string $title): string
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        $title = trim($title, " \t\"'“”‘’«»");
        $title = (string) preg_replace('/[.。۔]+$/u', '', $title);

        return self::fit($title);
    }

    /** The first words of the transcript, as the title without a key. */
    public static function firstWords(string $text): string
    {
        $words = array_slice(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, self::FIRST_WORDS);
        $title = rtrim(implode(' ', $words), ' ,،.۔;:!?؟');

        return self::fit($title);
    }

    private static function fit(string $title): string
    {
        if (mb_strlen($title) <= self::MAX) {
            return $title;
        }
        $cut = mb_substr($title, 0, self::MAX - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 20 ? mb_substr($cut, 0, $space) : $cut, ' ,،-—').'…';
    }

    private function configured(): bool
    {
        $key = $this->vault->get('anthropic', 'api_key');

        return $key !== null && $key !== '';
    }

    /** @param  array{input_tokens: int, output_tokens: int, model: string}  $usage */
    private function bill(CaptureSession $session, array $usage): void
    {
        [$in, $out] = ClaudeTitleWriter::PRICES[$usage['model']] ?? ClaudeTitleWriter::PRICES[ClaudeTitleWriter::MODEL];
        UsageLedgerEntry::query()->withoutGlobalScopes()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'device_id' => $session->device_id,
            'capture_session_id' => $session->id,
            'client_id' => $session->client_id,
            'report_id' => 'title/'.$session->id.'/'.Str::ulid(),
            'source' => UsageLedgerEntry::SOURCE_SERVER,
            'use_case' => 'auto_title',
            'provider' => 'claude',
            'model' => mb_substr($usage['model'], 0, 64),
            'duration_ms' => 0,
            'channels' => 1,
            'billed_ms' => 0,
            'input_tokens' => $usage['input_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'unit_price_usd' => 0,
            'cost_usd' => round(($usage['input_tokens'] * $in + $usage['output_tokens'] * $out) / 1_000_000, 6),
            'billable' => true,
            'occurred_at' => now(),
        ]);
    }
}
