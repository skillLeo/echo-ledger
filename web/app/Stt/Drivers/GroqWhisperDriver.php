<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\SessionMarkers;
use App\Services\SettingsRepository;
use App\Stt\Audio\AudioPrep;
use App\Stt\ChannelLanguage;
use App\Stt\Contracts\ClipTranscriber;
use App\Stt\Contracts\SttProvider;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\Data\Segment;
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use App\Stt\Data\Word;
use App\Stt\Exceptions\UnsupportedFeature;
use App\Stt\LanguageChoice;
use App\Stt\ScriptCheck;
use App\Stt\TranscriptNormalizer;
use App\Stt\WhisperGuards;
use App\Support\UrduText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Whisper large-v3 on Groq (docs checked 2026-10-28: console.groq.com/docs/speech-to-text, model page whisper-large-v3):
 * `POST https://api.groq.com/openai/v1/audio/transcriptions` (multipart): `model`, `file` (≤ 25 MB) or `url` (≤ 100 MB),
 * `language` (ISO-639-1), `prompt` (≤ 224 tokens), `response_format=verbose_json`, `temperature`,
 * `timestamp_granularities[]` (word, segment). US$0.111 per audio hour, at least 10 s billed per request.
 *
 * Each side of the call is transcribed on its own as 16 kHz mono, cut at pauses into chunks of ten minutes at most
 * (App\Stt\Audio\AudioPrep, ffmpeg on the server); silent stretches are not sent. Per chunk: the recording's language
 * (or Auto, then only the languages Auto may pick: anything else is run again in the fallback language, Hindi is
 * Urdu), Hindi letters run again forced as Urdu. On Auto each side then gets one language ([ChannelLanguage]): Urdu
 * anywhere on it makes it Urdu and its chunks heard otherwise run again as Urdu (a long, pure English stretch stays
 * English; nothing is ever forced to English). Whisper's invented text removed ([WhisperGuards]), low-confidence
 * lines marked uncertain, Urdu letter forms normalised. Synchronous: the job stores the result directly.
 */
final class GroqWhisperDriver implements ClipTranscriber, SttProvider
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/audio/transcriptions';

    /** Direct uploads above this go as a short-lived signed link instead (the documented attachment limit is 25 MB). */
    public const MAX_UPLOAD_BYTES = 24 * 1024 * 1024;

    /** Groq bills at least this much audio per request. */
    public const MIN_BILLED_MS = 10_000;

    /** @var list<array{at_ms: int, language: string|null}> the language marks of the recording being transcribed */
    private array $marks = [];

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
        private readonly ChunkedUploadService $uploads,
        private readonly AudioPrep $prep,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::Groq;
    }

    public function capabilities(): ProviderCapabilities
    {
        // Whisper takes no keyterm list: names and terms go into the style prompt instead (a few, short).
        return new ProviderCapabilities(CompletionMode::Sync, 'cloud', false, false, [], 40, 50, 6, 0, 0, usesChannelArtifacts: true);
    }

    public function model(): string
    {
        return (string) $this->settings->get('groq.model', 'whisper-large-v3');
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        throw new UnsupportedFeature('Groq Whisper writes final transcripts only.');
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $language = LanguageChoice::normalize($request->language);
        // The chip changed during the recording: each chunk takes the language in effect where it starts (the user's
        // marks win over detection); without marks every chunk has the recording's language.
        $this->marks = SessionMarkers::cleanLanguageMarks($request->session->language_marks ?? []);
        $prompt = self::stylePrompt($language, $request->keyterms);
        $sides = $this->sides($request);
        $segments = [];
        $removed = [];
        $billedMs = 0;
        $chunks = 0;
        $detected = [];
        $forced = 0;
        $rerun = 0;
        /** @var array<int, string|null> $sideLanguages channel => the language decided for it on Auto */
        $sideLanguages = [];
        $temps = [];
        try {
            // Plan every side first, so the apps can show "chunks done of total" while the requests run.
            $planned = [];
            foreach ($sides as $channel => [$path, $recording]) {
                $planned[$channel] = $this->chunks($path, $recording, $channel, count($sides), $temps);
            }
            $total = array_sum(array_map(count(...), $planned));
            foreach ($planned as $channel => $list) {
                $heard = [];
                foreach ($list as $i => $chunk) {
                    $this->progress($request->transcript, $chunks, $total);
                    $chunks++;
                    $chunkLanguage = $this->marks === [] ? $language
                        // Where the piece's own part begins (an overlapped piece starts 1 s before the chip changed).
                        : LanguageChoice::normalize(SessionMarkers::languageAt($this->marks, (int) round($chunk['keep_from'] * 1000), $language));
                    $heard[$i] = [$chunkLanguage, $this->transcribeChunk($chunk, $chunkLanguage, $chunkLanguage === $language ? $prompt : self::stylePrompt($chunkLanguage, $request->keyterms))];
                }
                // Auto: one language for the whole side; the chunks Whisper heard otherwise run again in it.
                $auto = array_keys(array_filter($heard, fn (array $h): bool => $h[0] === null));
                if ($auto !== []) {
                    $summary = fn (int $i): array => ['language' => $heard[$i][1]['language'], 'text' => self::textOf($heard[$i][1]['segments']), 'seconds' => $list[$i]['end'] - $list[$i]['start']];
                    $side = ChannelLanguage::decide(array_map($summary, $auto));
                    $sideLanguages[$channel] = $side;
                    foreach ($auto as $i) {
                        $as = ChannelLanguage::rerunAs($side, $summary($i));
                        if ($as === null) {
                            continue;
                        }
                        $again = $this->transcribeChunk($list[$i], $as, self::stylePrompt($as, $request->keyterms));
                        $again['requests'] += $heard[$i][1]['requests'];
                        $again['forced'] += $heard[$i][1]['forced'] + 1;
                        $heard[$i][1] = $again;
                        $rerun++;
                    }
                }
                foreach ($heard as $i => [, $result]) {
                    $chunk = $list[$i];
                    $forced += $result['forced'];
                    $billedMs += max(self::MIN_BILLED_MS, (int) round(($chunk['end'] - $chunk['start']) * 1000)) * $result['requests'];
                    if ($result['language'] !== null) {
                        $detected[$result['language']] = ($detected[$result['language']] ?? 0) + 1;
                    }
                    $guarded = WhisperGuards::filter($result['segments']);
                    foreach ($guarded['removed'] as $r) {
                        $removed[] = ['channel' => $channel, 'at_ms' => (int) round(($chunk['start'] + $r['start']) * 1000), 'reason' => $r['reason'], 'text' => $r['text']];
                    }
                    $pieceSegments = $this->toSegments($guarded['kept'], $result['words'], $chunk['start'], $channel, count($sides), $result['language'] ?? $language);
                    // The overlap of a cut inside speech was heard by the chunk before: keep each word once.
                    $keepFromMs = (int) round($chunk['keep_from'] * 1000);
                    array_push($segments, ...array_values(array_filter(array_map(fn (Segment $s): ?Segment => self::keepFrom($s, $keepFromMs), $pieceSegments))));
                }
            }
        } finally {
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        $segments = TranscriptNormalizer::sortByStart($segments);
        if (count($sides) === 2) {
            $segments = TranscriptNormalizer::dedupeBleed($segments);
        }
        arsort($detected);
        // On Auto a side that is Urdu (or Arabic) makes the recording so: Urdu with English words.
        $decided = array_values(array_filter($sideLanguages));
        $recordingLanguage = $language ?? (in_array('ur', $decided, true) ? 'ur' : (in_array('ar', $decided, true) ? 'ar' : (array_key_first($detected) ?: null)));

        return SubmitResult::completed(new NormalizedTranscript(
            TranscriptNormalizer::plainText($segments),
            $segments,
            $recordingLanguage,
            array_filter([
                'model' => $this->model(),
                'audio_channels' => count($sides),
                'billed_audio_ms' => $billedMs,
                'chunks' => $chunks,
                'chunk_languages' => $detected,
                'channel_languages' => $sideLanguages,
                'rerun_chunks' => $rerun,
                'forced_chunks' => $forced,
                'removed_segments' => array_slice($removed, 0, 50),
                'removed_count' => count($removed),
                'audio_prep' => $this->prep->available() ? 'ffmpeg' : 'whole_file',
            ], fn ($v): bool => $v !== []),
        ));
    }

    /**
     * The sides to transcribe: the per-channel files a device uploaded, else the two channels of a stereo file
     * (split by ffmpeg), else the one mono file.
     *
     * @return array<int, array{0: string, 1: ?Recording}> channel => [path, recording when the file is a stored one]
     */
    private function sides(FinalJobRequest $request): array
    {
        if ($request->hasChannelArtifacts()) {
            $roles = $request->session->recordings()->get()->keyBy(fn (Recording $r): string => $r->role->value);

            return [1 => [(string) $request->channel1Path, $roles->get('channel_1')], 2 => [(string) $request->channel2Path, $roles->get('channel_2')]];
        }
        if ($request->isStereo() && $this->prep->available()) {
            return [1 => [$request->primaryPath, null], 2 => [$request->primaryPath, null]];
        }

        return [1 => [$request->primaryPath, $request->primary]];
    }

    /**
     * [$segment] from [$keepFromMs] on (since v1.3, for the 1 s overlap after a cut inside speech): a line wholly in the
     * overlap is dropped, one wholly after it is kept, and one across it keeps only its words from the cut on (all of it
     * when the engine gave no word times: a word twice is better than a word lost). Pure; unit-tested.
     */
    public static function keepFrom(Segment $segment, int $keepFromMs): ?Segment
    {
        $tolerance = 250;
        if ($segment->startMs >= $keepFromMs - $tolerance) {
            return $segment;
        }
        if ($segment->endMs <= $keepFromMs + $tolerance) {
            return null;
        }
        if ($segment->words === []) {
            return $segment;
        }
        $words = array_values(array_filter($segment->words, fn (Word $w): bool => $w->startMs >= $keepFromMs - 100));
        if ($words === []) {
            return null;
        }

        return new Segment($segment->channel, $segment->speaker, $words[0]->startMs, $segment->endMs,
            trim(implode(' ', array_map(fn (Word $w): string => trim($w->text), $words))), $words, $segment->uncertain);
    }

    /**
     * The pieces of one side to send: with ffmpeg, speech-only chunks of at most ten minutes (exact offsets); without
     * it, the whole file (a signed link when it is too big to upload).
     *
     * @param  list<string>  $temps
     * @return list<array{path: ?string, url: ?string, start: float, end: float, keep_from: float}>
     */
    private function chunks(string $path, ?Recording $recording, int $channel, int $sides, array &$temps): array
    {
        if (! $this->prep->available()) {
            $size = (int) @filesize($path);
            if ($size > self::MAX_UPLOAD_BYTES && $recording !== null) {
                return [['path' => null, 'url' => URL::temporarySignedRoute('api.recordings.audio', now()->addMinutes(30), ['recording' => $recording->id]), 'start' => 0.0, 'end' => ($recording->duration_ms ?? 0) / 1000, 'keep_from' => 0.0]];
            }

            return [['path' => $path, 'url' => null, 'start' => 0.0, 'end' => ($recording->duration_ms ?? 0) / 1000, 'keep_from' => 0.0]];
        }
        $wav = $temps[] = $this->uploads->tempPath('groq-side');
        // A two-sided file split here takes one channel; a per-channel file or a mono file is taken as it is.
        $this->prep->extractChannel($path, $sides === 2 && $recording === null ? $channel : null, $wav);
        $duration = $this->prep->durationSeconds($wav);
        $cuts = array_map(fn (array $m): float => $m['at_ms'] / 1000, $this->marks);
        $silences = $this->prep->silences($wav);
        $plan = AudioPrep::plan($duration, $silences, (float) $this->settings->get('audio.chunk_seconds', 600), 3.0, $cuts);
        $out = [];
        // Cuts inside speech overlap by 1 s; the join keeps each word once (see toSegments).
        foreach (AudioPrep::overlapHardCuts($plan, $silences) as $i => $piece) {
            $file = $temps[] = $this->uploads->tempPath('groq-chunk-'.$i).'.flac';
            $out[] = ['path' => $this->prep->cut($wav, $piece['start'], $piece['end'], $file), 'url' => null, 'start' => $piece['start'], 'end' => $piece['end'], 'keep_from' => $piece['keep_from']];
        }

        return $out;
    }

    /**
     * One chunk, with the language rules: a forced language is sent as is; Auto takes what Whisper detects when it
     * is one Auto may pick (Hindi is Urdu), else runs again in the fallback; Hindi letters run again as Urdu.
     *
     * @param  array{path: ?string, url: ?string, start: float, end: float, keep_from: float}  $chunk
     * @return array{segments: list<array<string, mixed>>, words: list<array<string, mixed>>, language: ?string, forced: int, requests: int}
     */
    private function transcribeChunk(array $chunk, ?string $language, string $prompt): array
    {
        $requests = 1;
        $forced = 0;
        $json = $this->request($chunk, $language, $prompt);
        $detected = LanguageChoice::fromDetected($json['language'] ?? null);
        $used = $language ?? $detected;
        $text = implode(' ', array_map(fn ($s): string => (string) ($s['text'] ?? ''), (array) ($json['segments'] ?? [])));
        $retry = null;
        if (ScriptCheck::hasDevanagari($text) && ScriptCheck::wrongScript($used, $text) === 'devanagari' && $used !== null) {
            $retry = 'ur';
        } elseif ($language === null && trim($text) !== '' && ! LanguageChoice::allows(null, $detected, $this->settings)) {
            $retry = LanguageChoice::fallback($this->settings);
        } elseif ($language === null && ScriptCheck::wrongScript(null, $text) === 'devanagari') {
            $retry = 'ur';
        }
        if ($retry !== null) {
            $json = $this->request($chunk, $retry, self::stylePrompt($retry, []).' '.$prompt);
            $used = $retry;
            $requests++;
            $forced++;
        }

        return [
            'segments' => array_values((array) ($json['segments'] ?? [])),
            'words' => array_values((array) ($json['words'] ?? [])),
            'language' => $used,
            'forced' => $forced,
            'requests' => $requests,
        ];
    }

    /** @param  list<array<string, mixed>>  $segments  Whisper's segments of one chunk */
    private static function textOf(array $segments): string
    {
        return trim(implode(' ', array_map(fn ($s): string => trim((string) ($s['text'] ?? '')), $segments)));
    }

    /**
     * @param  array{path: ?string, url: ?string, start: float, end: float, keep_from: float}  $chunk
     * @return array<string, mixed>
     */
    private function request(array $chunk, ?string $language, string $prompt): array
    {
        $fields = [
            'model' => $this->model(),
            'response_format' => 'verbose_json',
            'temperature' => '0',
            'timestamp_granularities[]' => ['segment', 'word'],
        ];
        if ($language !== null) {
            $fields['language'] = $language;
        }
        if ($prompt !== '') {
            $fields['prompt'] = $prompt;
        }
        $http = Http::withToken($this->vault->require('groq', 'api_key'))->acceptJson()->connectTimeout(15)->timeout(600)->asMultipart();
        $parts = [];
        foreach ($fields as $name => $value) {
            foreach ((array) $value as $v) {
                $parts[] = ['name' => $name, 'contents' => (string) $v];
            }
        }
        if ($chunk['url'] !== null) {
            $parts[] = ['name' => 'url', 'contents' => $chunk['url']];

            return (array) $http->post(self::ENDPOINT, $parts)->throw()->json();
        }
        $stream = fopen((string) $chunk['path'], 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read audio for Groq.');
        }
        try {
            $parts[] = ['name' => 'file', 'contents' => $stream, 'filename' => basename((string) $chunk['path'])];

            return (array) $http->post(self::ENDPOINT, $parts)->throw()->json();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Whisper segments (times in seconds inside the chunk) as ours on the recording's timeline, with the words that
     * fall inside each one; Urdu text gets Urdu letter forms.
     *
     * @param  list<array<string, mixed>>  $kept
     * @param  list<array<string, mixed>>  $words
     * @return list<Segment>
     */
    private function toSegments(array $kept, array $words, float $offsetS, int $channel, int $sides, ?string $language): array
    {
        $speaker = TranscriptNormalizer::speakerFor($channel, $sides);
        $urdu = $language === 'ur';
        $out = [];
        foreach ($kept as $segment) {
            $start = (float) ($segment['start'] ?? 0);
            $end = (float) ($segment['end'] ?? $start);
            $inside = array_values(array_filter($words, fn (array $w): bool => (float) ($w['start'] ?? -1) >= $start - 0.01 && (float) ($w['end'] ?? 0) <= $end + 0.01));
            $text = trim((string) $segment['text']);
            $out[] = new Segment(
                $channel,
                $speaker,
                (int) round(($offsetS + $start) * 1000),
                (int) round(($offsetS + $end) * 1000),
                $urdu ? UrduText::normaliseUrdu($text) : $text,
                array_map(fn (array $w): Word => new Word(
                    $urdu ? UrduText::normaliseUrdu(trim((string) ($w['word'] ?? ''))) : trim((string) ($w['word'] ?? '')),
                    (int) round(($offsetS + (float) $w['start']) * 1000),
                    (int) round(($offsetS + (float) $w['end']) * 1000),
                ), $inside),
                (bool) ($segment['uncertain'] ?? false),
            );
        }

        return $out;
    }

    /**
     * The style prompt: Whisper continues in the style of its prompt, so an Urdu prompt in Urdu script with English
     * business words in Latin letters keeps Urdu in Urdu script and English words in English. Names and terms
     * follow (spelling only). Kept well under the 224-token limit.
     *
     * @param  list<string>  $keyterms
     */
    public static function stylePrompt(?string $language, array $keyterms): string
    {
        $style = match ($language) {
            'ur' => 'السلام علیکم، آج کی meeting میں ہم invoice، payment اور deadline کی بات کریں گے۔ ٹھیک ہے، میں email بھیج دوں گا۔',
            'ar' => 'مرحبا، في اجتماع اليوم سنتحدث عن invoice و payment و deadline. حسناً، سأرسل email.',
            'en' => 'Hello, in today\'s meeting we will discuss the invoice, the payment and the deadline.',
            default => '',
        };
        $names = array_slice(array_values(array_filter(array_map('trim', $keyterms), fn (string $k): bool => $k !== '')), 0, 20);
        $tail = $names === [] ? '' : ' '.implode(', ', $names).'.';

        return mb_substr(trim($style.$tail), 0, 600);
    }

    /** Double-check (since v1.2): one short clip, in the recording's language. Groq keeps no copy. */
    public function transcribeClip(string $path, float $seconds, ?string $language): array
    {
        $language = LanguageChoice::normalize($language);
        $json = $this->request(['path' => $path, 'url' => null, 'start' => 0.0, 'end' => $seconds, 'keep_from' => 0.0], $language, self::stylePrompt($language, []));
        $kept = WhisperGuards::filter(array_values((array) ($json['segments'] ?? [])))['kept'];
        $text = trim(implode(' ', array_map(fn (array $s): string => trim((string) ($s['text'] ?? '')), $kept)));

        return [
            'text' => $language === 'ur' ? UrduText::normaliseUrdu($text) : $text,
            'billed_ms' => max(self::MIN_BILLED_MS, (int) round($seconds * 1000)),
        ];
    }

    /** "Chunks done of total" for the apps' progress line (`meta.progress`), written as each chunk starts. */
    private function progress(Transcript $transcript, int $done, int $total): void
    {
        $meta = $transcript->meta ?? [];
        $meta['progress'] = ['chunks_done' => $done, 'chunks_total' => $total];
        Transcript::query()->whereKey($transcript->id)->update(['meta' => json_encode($meta)]);
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        throw new UnsupportedFeature('Groq transcription is synchronous; no webhooks.');
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        return null; // sync: results are stored by TranscribeFinalJob directly
    }

    public function deleteRemote(Transcript $transcript): void
    {
        // Groq keeps no file from a transcription request to delete (the audio is sent with the request).
    }
}
