<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TranscriptStatus;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transcript editing (docs/API.md "Editing transcripts", since v1.1): segment text edits, find and replace,
 * restoring the provider's original, the revision list, and speaker names per session (remembered per client).
 * Timings never change: an edited segment keeps its start and end and drops its word timings.
 */
final class TranscriptEditor
{
    /** Revisions kept per transcript besides the original. */
    public const MAX_REVISIONS = 50;

    public const MAX_SEGMENT_CHARS = 5000;

    public const MAX_SPEAKER_CHARS = 60;

    /**
     * Replaces the text of the given segments (index => text) and saves a revision.
     *
     * @param  array<int, string>  $edits
     */
    public function edit(Transcript $transcript, array $edits, ?string $author = null, string $source = TranscriptRevision::EDIT): Transcript
    {
        $this->assertEditable($transcript);
        $segments = $transcript->segments ?? [];
        foreach ($edits as $index => $text) {
            if (! array_key_exists($index, $segments)) {
                throw new ApiError(422, 'segment_not_found', "There is no segment {$index} in this transcript.");
            }
            $clean = self::cleanText($text);
            if ($clean === '') {
                throw new ApiError(422, 'segment_empty', 'A segment cannot be empty. Delete the words you do not want instead.');
            }
            if (($segments[$index]['text'] ?? null) === $clean) {
                continue;
            }
            $segment = $segments[$index];
            $segment['text'] = $clean;
            // The word timings no longer match the text, and the user's words are not unsure.
            unset($segment['words'], $segment['uncertain'], $segment['check']);
            $segments[$index] = $segment;
        }

        return $this->save($transcript, $segments, $source, $author);
    }

    /**
     * Find and replace across every segment. Returns the number of replacements (0 saves nothing).
     *
     * @return array{0: Transcript, 1: int}
     */
    public function replace(Transcript $transcript, string $find, string $replace, bool $matchCase = false, bool $wholeWord = false, ?string $author = null): array
    {
        $this->assertEditable($transcript);
        if (trim($find) === '') {
            throw new ApiError(422, 'find_empty', 'Type the text to find.');
        }
        $pattern = self::pattern($find, $matchCase, $wholeWord);
        $edits = [];
        $count = 0;
        foreach ($transcript->segments ?? [] as $index => $segment) {
            $text = (string) ($segment['text'] ?? '');
            $replaced = preg_replace_callback($pattern, static fn (): string => $replace, $text, -1, $n);
            if ($n > 0 && $replaced !== null && self::cleanText($replaced) !== '') {
                $edits[$index] = $replaced;
                $count += $n;
            }
        }
        if ($count === 0) {
            return [$transcript, 0];
        }

        return [$this->edit($transcript, $edits, $author, TranscriptRevision::REPLACE), $count];
    }

    /** Puts the provider's original text back (a revision records it); a transcript never edited is unchanged. */
    public function restoreOriginal(Transcript $transcript, ?string $author = null): Transcript
    {
        $original = $transcript->revisions()->where('source', TranscriptRevision::ORIGINAL)->first();
        if (! $original instanceof TranscriptRevision) {
            return $transcript;
        }
        DB::transaction(function () use ($transcript, $original, $author): void {
            $transcript->update(['segments' => $original->segments, 'text' => $original->text, 'edited_at' => null]);
            $transcript->revisions()->create([
                'source' => TranscriptRevision::RESTORE, 'segments' => $original->segments, 'text' => $original->text, 'author' => $author,
            ]);
        });

        return $transcript->refresh();
    }

    /** @return Collection<int, TranscriptRevision> newest first */
    public function revisions(Transcript $transcript): Collection
    {
        return $transcript->revisions()->latest('created_at')->latest('id')->get();
    }

    /**
     * Speaker names of a session: the client's remembered names, overridden by the session's own.
     *
     * @return array<string, string> speaker label (as in the segments) → name
     */
    public function speakerNames(CaptureSession $session): array
    {
        return array_merge($session->client->speaker_names ?? [], $session->speaker_names ?? []);
    }

    /**
     * Renames speakers of a session; with [$rememberForClient] the names also become the client's defaults. A blank
     * name removes the rename.
     *
     * @param  array<string, string|null>  $names
     * @return array<string, string>
     */
    public function renameSpeakers(CaptureSession $session, array $names, bool $rememberForClient = false): array
    {
        $current = $session->speaker_names ?? [];
        foreach ($names as $label => $name) {
            $label = SessionMarkers::cleanText((string) $label);
            if ($label === null) {
                continue;
            }
            $clean = SessionMarkers::cleanText($name);
            if ($clean === null || $clean === $label) {
                unset($current[$label]);
            } else {
                $current[$label] = mb_substr($clean, 0, self::MAX_SPEAKER_CHARS);
            }
        }
        $session->update(['speaker_names' => $current === [] ? null : $current]);
        if ($rememberForClient && $session->client !== null) {
            $client = $session->client;
            $remembered = array_merge($client->speaker_names ?? [], $current);
            foreach ($names as $label => $name) {
                if (SessionMarkers::cleanText($name) === null) {
                    unset($remembered[(string) $label]);
                }
            }
            $client->update(['speaker_names' => $remembered === [] ? null : $remembered]);
        }

        return $this->speakerNames($session->refresh());
    }

    /**
     * Saves whole segments (vocabulary replacements on a new transcript) with a revision, keeping the original.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    public function saveSegments(Transcript $transcript, array $segments, string $source, ?string $author): Transcript
    {
        return $this->save($transcript, $segments, $source, $author);
    }

    /** @param list<array<string, mixed>> $segments */
    private function save(Transcript $transcript, array $segments, string $source, ?string $author): Transcript
    {
        if ($segments === ($transcript->segments ?? [])) {
            return $transcript;
        }
        DB::transaction(function () use ($transcript, $segments, $source, $author): void {
            if (! $transcript->revisions()->where('source', TranscriptRevision::ORIGINAL)->exists()) {
                $transcript->revisions()->create([
                    'source' => TranscriptRevision::ORIGINAL, 'segments' => $transcript->segments ?? [], 'text' => $transcript->text,
                ]);
            }
            $text = self::plainText($segments);
            $transcript->update(['segments' => $segments, 'text' => $text, 'edited_at' => now()]);
            $transcript->revisions()->create(['source' => $source, 'segments' => $segments, 'text' => $text, 'author' => $author]);
            $this->prune($transcript);
        });

        return $transcript->refresh();
    }

    /** Keeps the original and the newest [MAX_REVISIONS] other revisions. */
    private function prune(Transcript $transcript): void
    {
        $keep = $transcript->revisions()->where('source', '!=', TranscriptRevision::ORIGINAL)
            ->latest('created_at')->latest('id')->limit(self::MAX_REVISIONS)->pluck('id');
        $transcript->revisions()->where('source', '!=', TranscriptRevision::ORIGINAL)->whereNotIn('id', $keep)->delete();
    }

    private function assertEditable(Transcript $transcript): void
    {
        if ($transcript->status !== TranscriptStatus::Completed) {
            throw new ApiError(409, 'transcript_not_ready', 'Only a finished transcript can be edited.');
        }
    }

    /**
     * `Speaker: text` per line, like the provider transcripts (`TranscriptNormalizer::plainText`).
     *
     * @param  list<array<string, mixed>>  $segments
     */
    public static function plainText(array $segments): string
    {
        return implode("\n", array_map(static fn (array $s): string => ((string) ($s['speaker'] ?? '')).': '.((string) ($s['text'] ?? '')), $segments));
    }

    /** Trimmed, line breaks and runs of spaces collapsed, at most [MAX_SEGMENT_CHARS] characters. */
    public static function cleanText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, self::MAX_SEGMENT_CHARS);
    }

    /** A literal search pattern, optionally case-sensitive and whole-word (Unicode letters and digits). */
    public static function pattern(string $find, bool $matchCase, bool $wholeWord): string
    {
        $quoted = preg_quote($find, '/');
        if ($wholeWord) {
            $quoted = '(?<![\p{L}\p{N}_])'.$quoted.'(?![\p{L}\p{N}_])';
        }

        return '/'.$quoted.'/u'.($matchCase ? '' : 'i');
    }
}
