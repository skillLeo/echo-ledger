<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\Audio\AudioPrep;
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
use App\Stt\DialectHint;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\UnsupportedFeature;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageChoice;
use App\Stt\TranscriptNormalizer;
use App\Support\UrduText;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Google Gemini (since v1.5, final transcripts only): an Urdu challenger for the Accuracy Lab. Docs checked 2026-11-04
 * (ai.google.dev now redirects to aistudio.google.com/docs): the model is a setting (`gemini.model`), by default the
 * current Pro model `gemini-3.1-pro-preview`. No Pro model is marked stable: `gemini-2.5-pro` is deprecated (answered
 * by 3.8 Flash from 2026-12-01) and `gemini-3-pro-preview` is shut down. Audio counts 32 tokens a second; the paid tier
 * charges US$2 per million input tokens (prompts up to 200K tokens), about US$0.23 per audio hour, plus US$12 per
 * million tokens of the answer. A key from a project with billing enabled is the paid tier; on the free tier Google may
 * use what it is sent to improve its products (this model has no free tier).
 *
 * Per side of the call, like Whisper on Groq: the side as 16 kHz mono, cut at pauses into chunks of at most
 * `audio.chunk_seconds` (one answer may hold 65,536 tokens), silent stretches left out (App\Stt\Audio\AudioPrep). Each
 * chunk is uploaded with the Files API (resumable upload), transcribed by generateContent with strict verbatim rules
 * and a JSON schema (`segments: [{start_s, end_s, text}]`), and then deleted, whatever happened (Google would keep it
 * for 48 hours). Without ffmpeg each side is sent whole. Hindi letters are handled by the usual script check (the
 * transcript runs once more as Urdu, then such lines are flagged). Synchronous: the job stores the result directly.
 */
final class GeminiDriver implements ClipTranscriber, SttProvider
{
    public const DEFAULT_MODEL = 'gemini-3.1-pro-preview';

    /** A model id as Google writes them (it goes into the request path). */
    public const MODEL_PATTERN = '/^gemini-[a-z0-9][a-z0-9.\-]{1,70}$/';

    /** Offered in Settings: the current Pro model first, the flagship and the last stable Flash (docs, 2026-11-04). */
    public const SUGGESTED_MODELS = ['gemini-3.1-pro-preview', 'gemini-4-argon', 'gemini-3.6-flash'];

    private const BASE = 'https://generativelanguage.googleapis.com';

    /** An upload Gemini is still reading is asked about this often, for at most [ACTIVE_WAIT_SECONDS]. */
    private const POLL_SECONDS = 2;

    private const ACTIVE_WAIT_SECONDS = 60;

    /** The longest answer the Pro models give; a ten-minute chunk of speech needs a small part of it. */
    private const MAX_OUTPUT_TOKENS = 65_536;

    /** The rules every request carries: the words as said, nothing else. */
    public const RULES = <<<'TXT'
        You transcribe recorded calls and meetings word for word.
        Rules:
        - Write exactly what is said, in the order it is said. Never paraphrase, summarise, shorten, correct or translate anything.
        - When a word or phrase cannot be heard clearly, write [unclear] in its place. Never guess.
        - Write Urdu words in Urdu script (Arabic letters, as in Nastaliq) and English words in English, in Latin letters. Never use Devanagari (Hindi) letters. Other languages stay in their own script.
        - Keep fillers (um, uh, ہاں، اچھا), false starts and repeated words exactly as said.
        - One segment per sentence or speaker turn, with its start and end in seconds from the start of this audio.
        - No speaker names, notes, descriptions of sounds or music, and no words that were not spoken. If nobody speaks, return no segments.
        - language: the ISO 639-1 code of the main spoken language (ur for Urdu, en for English).
        TXT;

    /** The answer's shape (JSON Schema): the main language and the spoken segments. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'language' => ['type' => 'string', 'description' => 'ISO 639-1 code of the main spoken language'],
            'segments' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'start_s' => ['type' => 'number', 'description' => 'Start, in seconds from the start of the audio'],
                        'end_s' => ['type' => 'number', 'description' => 'End, in seconds from the start of the audio'],
                        'text' => ['type' => 'string', 'description' => 'The words exactly as spoken'],
                    ],
                    'required' => ['start_s', 'end_s', 'text'],
                ],
            ],
        ],
        'required' => ['language', 'segments'],
    ];

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
        private readonly ChunkedUploadService $uploads,
        private readonly AudioPrep $prep,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::Gemini;
    }

    public function capabilities(): ProviderCapabilities
    {
        // Names and terms go into the prompt (a few, short), as for Whisper.
        return new ProviderCapabilities(CompletionMode::Sync, 'cloud', false, false, [], 40, 50, 6, 0, 0, usesChannelArtifacts: true);
    }

    public function model(): string
    {
        return self::modelOr($this->settings->get('gemini.model', self::DEFAULT_MODEL));
    }

    /** [$value] when it reads as a Gemini model id, else the default. */
    public static function modelOr(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match(self::MODEL_PATTERN, $value) === 1 ? $value : self::DEFAULT_MODEL;
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        throw new UnsupportedFeature('Gemini writes final transcripts only.');
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $key = $this->vault->require('gemini', 'api_key');
        $language = LanguageChoice::normalize($request->language);
        $keyterms = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), false);
        $sides = $this->sides($request);
        $segments = [];
        $billedMs = 0;
        $chunks = 0;
        $detected = [];
        $tokens = ['prompt' => 0, 'output' => 0, 'thoughts' => 0];
        $temps = [];
        try {
            // Plan every side first, so the apps can show "parts done of total" while the requests run.
            $planned = [];
            foreach ($sides as $channel => [$path, $recording, $mime]) {
                $planned[$channel] = $this->chunks($path, $recording, $mime, $channel, count($sides), $temps);
            }
            $total = array_sum(array_map(count(...), $planned));
            foreach ($planned as $channel => $list) {
                foreach ($list as $chunk) {
                    $this->progress($request->transcript, $chunks, $total);
                    $chunks++;
                    $answer = $this->transcribe($key, $chunk, $language, $keyterms);
                    $billedMs += (int) round(max(0.0, $chunk['end'] - $chunk['start']) * 1000);
                    foreach ($tokens as $kind => $count) {
                        $tokens[$kind] = $count + $answer['tokens'][$kind];
                    }
                    if ($answer['language'] !== null) {
                        $detected[$answer['language']] = ($detected[$answer['language']] ?? 0) + 1;
                    }
                    $keepFromMs = (int) round($chunk['keep_from'] * 1000);
                    $pieces = self::toSegments($answer['segments'], $chunk, $channel, count($sides), $language ?? $answer['language']);
                    // The overlap of a cut inside speech was heard by the chunk before: keep each line once.
                    array_push($segments, ...array_values(array_filter(array_map(fn (Segment $s): ?Segment => GroqWhisperDriver::keepFrom($s, $keepFromMs), $pieces))));
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

        return SubmitResult::completed(new NormalizedTranscript(
            TranscriptNormalizer::plainText($segments),
            $segments,
            $language ?? (array_key_first($detected) ?: null),
            array_filter([
                'model' => $this->model(),
                'audio_channels' => count($sides),
                'billed_audio_ms' => $billedMs,
                'chunks' => $chunks,
                'chunk_languages' => $detected,
                'tokens' => $tokens,
                'audio_prep' => $this->prep->available() ? 'ffmpeg' : 'whole_file',
            ], fn ($v): bool => $v !== []),
        ));
    }

    /**
     * Gemini's segments of one chunk (seconds inside the chunk) as ours on the recording's timeline: empty lines
     * dropped, times kept inside the chunk, Urdu letter forms normalised. Pure; unit-tested.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array{start: float, end: float}  $chunk
     * @return list<Segment>
     */
    public static function toSegments(array $items, array $chunk, int $channel, int $sides, ?string $language): array
    {
        $speaker = TranscriptNormalizer::speakerFor($channel, $sides);
        $length = max(0.0, $chunk['end'] - $chunk['start']);
        $out = [];
        foreach ($items as $item) {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($item['text'] ?? '')));
            if ($text === '') {
                continue;
            }
            $start = max(0.0, is_numeric($item['start_s'] ?? null) ? (float) $item['start_s'] : 0.0);
            $end = max($start, is_numeric($item['end_s'] ?? null) ? (float) $item['end_s'] : $start);
            if ($length > 0) {
                $start = min($start, $length);
                $end = min($end, $length + 1.0);
            }
            $out[] = new Segment(
                $channel,
                $speaker,
                (int) round(($chunk['start'] + $start) * 1000),
                (int) round(($chunk['start'] + $end) * 1000),
                $language === 'ur' ? UrduText::normaliseUrdu($text) : $text,
            );
        }

        return $out;
    }

    /**
     * The request's user turn: the language to expect, the chunk's length and the names that may come up.
     *
     * @param  list<string>  $keyterms
     */
    public static function prompt(?string $language, array $keyterms, float $seconds): string
    {
        $lines = [match ($language) {
            'ur' => 'Language: Urdu, with English words mixed in (normal in these calls).',
            'ar' => 'Language: Arabic, with English words mixed in.',
            'en' => 'Language: English.',
            null => 'Language: not given. Most of these calls are Urdu with English words; some are English.',
            default => 'Language: '.LanguageChoice::label($language).'.',
        }];
        if (($dialect = DialectHint::text($language)) !== null) {
            $lines[] = 'Dialect: '.$dialect;
        }
        if ($seconds > 0) {
            $lines[] = sprintf('The audio is %d:%02d long.', intdiv((int) round($seconds), 60), (int) round($seconds) % 60);
        }
        $names = array_slice(array_values(array_filter(array_map('trim', $keyterms), fn (string $k): bool => $k !== '')), 0, 40);
        if ($names !== []) {
            $lines[] = 'Names and terms that may be said (for their spelling only; never add one that was not said): '.implode(', ', $names).'.';
        }
        $lines[] = 'Transcribe this audio.';

        return implode("\n", $lines);
    }

    /**
     * The tie-breaker of the short-note double-check (since v1.5, Phase 1C): one side of a short note, words only.
     *
     * @return array{text: string, billed_ms: int}
     */
    public function transcribeClip(string $path, float $seconds, ?string $language): array
    {
        $key = $this->vault->require('gemini', 'api_key');
        $mime = str_ends_with($path, '.wav') ? 'audio/wav' : 'audio/flac';
        $answer = $this->transcribe($key, ['path' => $path, 'mime' => $mime, 'start' => 0.0, 'end' => $seconds, 'keep_from' => 0.0], LanguageChoice::normalize($language), []);
        $text = trim(implode(' ', array_map(fn (array $s): string => trim((string) ($s['text'] ?? '')), $answer['segments'])));

        return ['text' => $text, 'billed_ms' => (int) round($seconds * 1000)];
    }

    /**
     * One chunk: upload, wait until Gemini can read it, transcribe, and delete the upload again in every case.
     *
     * @param  array{path: string, mime: string, start: float, end: float, keep_from: float}  $chunk
     * @param  list<string>  $keyterms
     * @return array{segments: list<array<string, mixed>>, language: string|null, tokens: array{prompt: int, output: int, thoughts: int}}
     */
    private function transcribe(string $key, array $chunk, ?string $language, array $keyterms): array
    {
        $file = null;
        try {
            $file = $this->upload($key, $chunk['path'], $chunk['mime']);
            $file = $this->whenActive($key, $file);

            return $this->generate($key, $file, $language, $keyterms, $chunk['end'] - $chunk['start']);
        } finally {
            if ($file !== null) {
                $this->deleteUpload($key, $file['name']);
            }
        }
    }

    /**
     * Files API, resumable upload: start (the size and type), then the bytes with "upload, finalize".
     *
     * @return array{name: string, uri: string, mime: string, state: string}
     */
    private function upload(string $key, string $path, string $mime): array
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new RuntimeException('Cannot read audio for Gemini.');
        }
        $start = Http::withHeaders([
            'x-goog-api-key' => $key,
            'X-Goog-Upload-Protocol' => 'resumable',
            'X-Goog-Upload-Command' => 'start',
            'X-Goog-Upload-Header-Content-Length' => (string) $size,
            'X-Goog-Upload-Header-Content-Type' => $mime,
        ])->acceptJson()->connectTimeout(15)->timeout(60)
            ->post(self::BASE.'/upload/v1beta/files', ['file' => ['display_name' => 'echoledger-audio']])->throw();
        $url = trim((string) $start->header('X-Goog-Upload-URL'));
        // The bytes go only to Google's own upload host over HTTPS.
        if (! str_starts_with($url, 'https://') || ! str_ends_with((string) parse_url($url, PHP_URL_HOST), '.googleapis.com')) {
            throw new ProviderJobFailed('Gemini did not give an upload address.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read audio for Gemini.');
        }
        try {
            $json = Http::withHeaders(['X-Goog-Upload-Offset' => '0', 'X-Goog-Upload-Command' => 'upload, finalize'])
                ->acceptJson()->connectTimeout(15)->timeout(600)
                ->withBody(Utils::streamFor($stream), $mime)->post($url)->throw()->json();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $file = is_array($json) && is_array($json['file'] ?? null) ? $json['file'] : [];
        $name = (string) ($file['name'] ?? '');
        $uri = (string) ($file['uri'] ?? '');
        if (preg_match('#^files/[A-Za-z0-9_-]+$#', $name) !== 1 || $uri === '') {
            throw new ProviderJobFailed('Gemini did not accept the audio upload.');
        }

        return ['name' => $name, 'uri' => $uri, 'mime' => (string) ($file['mimeType'] ?? $mime), 'state' => (string) ($file['state'] ?? 'ACTIVE')];
    }

    /**
     * Gemini reads an upload before it can be used: asks again while it is PROCESSING, for at most a minute.
     *
     * @param  array{name: string, uri: string, mime: string, state: string}  $file
     * @return array{name: string, uri: string, mime: string, state: string}
     */
    private function whenActive(string $key, array $file): array
    {
        $waited = 0;
        while ($file['state'] === 'PROCESSING' && $waited < self::ACTIVE_WAIT_SECONDS) {
            Sleep::for(self::POLL_SECONDS)->seconds();
            $waited += self::POLL_SECONDS;
            $json = Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->connectTimeout(10)->timeout(30)
                ->get(self::BASE.'/v1beta/'.$file['name'])->throw()->json();
            $file['state'] = (string) ((is_array($json) ? ($json['state'] ?? null) : null) ?? 'ACTIVE');
        }
        if ($file['state'] === 'FAILED') {
            throw new ProviderJobFailed('Gemini could not read the audio.');
        }
        if ($file['state'] === 'PROCESSING') {
            throw new ProviderJobFailed('Gemini was still reading the audio after a minute.');
        }

        return $file;
    }

    /**
     * generateContent with the verbatim rules and the JSON schema; the answer's segments, language and token counts.
     *
     * @param  array{name: string, uri: string, mime: string, state: string}  $file
     * @param  list<string>  $keyterms
     * @return array{segments: list<array<string, mixed>>, language: string|null, tokens: array{prompt: int, output: int, thoughts: int}}
     */
    private function generate(string $key, array $file, ?string $language, array $keyterms, float $seconds): array
    {
        $model = $this->model();
        $config = ['responseMimeType' => 'application/json', 'responseJsonSchema' => self::SCHEMA, 'maxOutputTokens' => self::MAX_OUTPUT_TOKENS];
        if (preg_match('/^gemini-(\d+)/', $model, $m) === 1 && (int) $m[1] >= 3) {
            // Listening needs little reasoning: low thinking (Gemini 3 and later) keeps the answer quick and its billed
            // tokens few.
            $config['thinkingConfig'] = ['thinkingLevel' => 'low'];
        }
        $json = Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->connectTimeout(15)->timeout(600)
            ->post(self::BASE.'/v1beta/models/'.$model.':generateContent', [
                'systemInstruction' => ['parts' => [['text' => self::RULES]]],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [
                        ['fileData' => ['mimeType' => $file['mime'], 'fileUri' => $file['uri']]],
                        ['text' => self::prompt($language, $keyterms, $seconds)],
                    ],
                ]],
                'generationConfig' => $config,
            ])->throw()->json();
        $json = is_array($json) ? $json : [];
        $blocked = $json['promptFeedback']['blockReason'] ?? null;
        if (is_string($blocked) && $blocked !== '') {
            throw new ProviderJobFailed('Gemini refused the audio ('.$blocked.').');
        }
        $candidate = is_array($json['candidates'][0] ?? null) ? $json['candidates'][0] : [];
        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        if ($finish === 'MAX_TOKENS') {
            throw new ProviderJobFailed('Gemini stopped before the end of the audio: the answer was too long.');
        }
        if (! in_array($finish, ['STOP', 'FINISH_REASON_UNSPECIFIED'], true)) {
            throw new ProviderJobFailed('Gemini stopped ('.$finish.').');
        }
        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true) {
                $text .= (string) ($part['text'] ?? '');
            }
        }
        $answer = json_decode($text, true);
        if (! is_array($answer) || ! is_array($answer['segments'] ?? null)) {
            throw new ProviderJobFailed('Gemini did not answer with a transcript.');
        }
        $usage = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];

        return [
            'segments' => array_values(array_filter($answer['segments'], 'is_array')),
            'language' => LanguageChoice::fromDetected($answer['language'] ?? null),
            'tokens' => [
                'prompt' => (int) ($usage['promptTokenCount'] ?? 0),
                'output' => (int) ($usage['candidatesTokenCount'] ?? 0),
                'thoughts' => (int) ($usage['thoughtsTokenCount'] ?? 0),
            ],
        ];
    }

    /** Deletes an upload; a failure is logged, never thrown (Google deletes it after 48 hours anyway). */
    private function deleteUpload(string $key, string $name): void
    {
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])->acceptJson()->connectTimeout(5)->timeout(15)
                ->delete(self::BASE.'/v1beta/'.$name);
            if ($response->failed() && $response->status() !== 404) {
                Log::warning('Gemini upload not deleted', ['file' => $name, 'status' => $response->status()]);
            }
        } catch (Throwable $e) {
            Log::warning('Gemini upload not deleted', ['file' => $name, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }

    /**
     * The sides to transcribe: the per-channel files a device uploaded, else the two channels of a stereo file
     * (split by ffmpeg), else the one file.
     *
     * @return array<int, array{0: string, 1: ?Recording, 2: string}> channel => [path, recording when the file is a stored one, type]
     */
    private function sides(FinalJobRequest $request): array
    {
        if ($request->hasChannelArtifacts()) {
            $roles = $request->session->recordings()->get()->keyBy(fn (Recording $r): string => $r->role->value);

            return [
                1 => [(string) $request->channel1Path, $roles->get('channel_1'), self::mime($request->channel1Mime)],
                2 => [(string) $request->channel2Path, $roles->get('channel_2'), self::mime($request->channel2Mime)],
            ];
        }
        if ($request->isStereo() && $this->prep->available()) {
            return [1 => [$request->primaryPath, null, self::mime($request->primary->mime)], 2 => [$request->primaryPath, null, self::mime($request->primary->mime)]];
        }

        return [1 => [$request->primaryPath, $request->primary, self::mime($request->primary->mime)]];
    }

    /**
     * The pieces of one side: with ffmpeg, speech-only FLAC chunks with exact offsets; without it, the whole file.
     *
     * @param  list<string>  $temps
     * @return list<array{path: string, mime: string, start: float, end: float, keep_from: float}>
     */
    private function chunks(string $path, ?Recording $recording, string $mime, int $channel, int $sides, array &$temps): array
    {
        if (! $this->prep->available()) {
            return [['path' => $path, 'mime' => $mime, 'start' => 0.0, 'end' => ($recording->duration_ms ?? 0) / 1000, 'keep_from' => 0.0]];
        }
        $wav = $temps[] = $this->uploads->tempPath('gemini-side');
        $this->prep->extractChannel($path, $sides === 2 && $recording === null ? $channel : null, $wav);
        $silences = $this->prep->silences($wav);
        $plan = AudioPrep::plan($this->prep->durationSeconds($wav), $silences, (float) $this->settings->get('audio.chunk_seconds', 600), 3.0);
        $out = [];
        foreach (AudioPrep::overlapHardCuts($plan, $silences) as $i => $piece) {
            $file = $temps[] = $this->uploads->tempPath('gemini-chunk-'.$i).'.flac';
            $out[] = ['path' => $this->prep->cut($wav, $piece['start'], $piece['end'], $file), 'mime' => 'audio/flac', 'start' => $piece['start'], 'end' => $piece['end'], 'keep_from' => $piece['keep_from']];
        }

        return $out;
    }

    /** The recording's type as Gemini names it (no codec parameters). */
    private static function mime(?string $mime): string
    {
        $type = strtolower(trim(explode(';', (string) $mime)[0]));

        return match ($type) {
            '', 'application/octet-stream' => 'audio/ogg',
            'audio/x-wav', 'audio/wave' => 'audio/wav',
            'audio/x-flac' => 'audio/flac',
            'audio/mp4', 'audio/x-m4a' => 'audio/m4a',
            default => $type,
        };
    }

    /** "Parts done of total" for the apps' progress line (`meta.progress`), written as each part starts. */
    private function progress(Transcript $transcript, int $done, int $total): void
    {
        $meta = $transcript->meta ?? [];
        $meta['progress'] = ['chunks_done' => $done, 'chunks_total' => $total];
        Transcript::query()->whereKey($transcript->id)->update(['meta' => json_encode($meta)]);
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        throw new UnsupportedFeature('Gemini transcription is synchronous; no webhooks.');
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        return null; // sync: results are stored by TranscribeFinalJob directly
    }

    public function deleteRemote(Transcript $transcript): void
    {
        // Every upload is deleted right after its request (see transcribe()); nothing else is kept.
    }
}
