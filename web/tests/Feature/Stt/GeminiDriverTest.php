<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Audio\AudioPrep;
use App\Stt\Drivers\GeminiDriver;
use App\Stt\LanguageRouter;
use App\Stt\SttManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Google Gemini (since v1.5, App\Stt\Drivers\GeminiDriver): per side and chunk the audio is uploaded with the Files API,
 * transcribed by generateContent with the verbatim rules and a JSON schema, and the upload is deleted whatever happened.
 */

const GEMINI_KEY = 'AIzaGeminiTestKey_1234567890abcdef';

const GEMINI_BASE = 'https://generativelanguage.googleapis.com';

beforeEach(function () {
    Queue::fake();
    Sleep::fake();
    [$this->device] = $this->enroll(platform: 'android');
    app(CredentialVault::class)->put('gemini', 'api_key', GEMINI_KEY);
    geminiNoPrep();
    $this->calls = [];
    $this->answers = [];
    $this->generateStatus = 200;
    $this->uploads = 0;
    Http::fake(function (HttpRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->calls[] = ['method' => $request->method(), 'url' => $request->url(), 'path' => $path, 'body' => $request->body(), 'headers' => $request->headers()];

        return match (true) {
            $request->method() === 'POST' && $path === '/upload/v1beta/files' && ! str_contains($request->url(), 'upload_id') => Http::response([], 200, [
                'X-Goog-Upload-URL' => GEMINI_BASE.'/upload/v1beta/files?upload_id=up'.(++$this->uploads).'&upload_protocol=resumable',
            ]),
            $request->method() === 'POST' && str_contains($request->url(), 'upload_id=') => Http::response(['file' => [
                'name' => 'files/audio'.$this->uploads, 'uri' => GEMINI_BASE.'/v1beta/files/audio'.$this->uploads, 'mimeType' => 'audio/ogg', 'state' => 'ACTIVE',
            ]], 200),
            $request->method() === 'POST' && str_ends_with($path, ':generateContent') => $this->generateStatus === 200
                ? Http::response(geminiAnswer(array_shift($this->answers) ?? []), 200)
                : Http::response(['error' => ['code' => $this->generateStatus, 'message' => 'The model is overloaded. Please try again later.', 'status' => 'UNAVAILABLE']], $this->generateStatus),
            $request->method() === 'DELETE' => Http::response([], 200),
            default => Http::response(['error' => ['message' => 'unexpected '.$request->method().' '.$path]], 404),
        };
    });
});

function geminiNoPrep(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return false;
        }
    });
}

/** Audio prep that cuts one mono side of 900 s at the pause at 500-502 s: two chunks, 0-501 s and 501-900 s. */
function geminiTwoChunks(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return true;
        }

        public function extractChannel(string $input, ?int $channel, string $out): string
        {
            file_put_contents($out, 'wav');

            return $out;
        }

        public function durationSeconds(string $wav): float
        {
            return 900.0;
        }

        public function silences(string $wav): array
        {
            return [[500.0, 502.0]];
        }

        public function cut(string $wav, float $startS, float $endS, string $out): string
        {
            file_put_contents($out, "chunk {$startS}-{$endS}");

            return $out;
        }
    });
}

/**
 * generateContent's answer around the transcript JSON.
 *
 * @param  array<string, mixed>  $transcript
 * @return array<string, mixed>
 */
function geminiAnswer(array $transcript): array
{
    return [
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => json_encode($transcript, JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1950, 'candidatesTokenCount' => 120, 'thoughtsTokenCount' => 40, 'totalTokenCount' => 2110],
    ];
}

function geminiTranscript(object $test, ?string $language = 'ur', int $channels = 1): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => $language, 'final_provider' => 'gemini'], ['channels' => $channels]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'gemini', 'status' => TranscriptStatus::Pending, 'language' => $language]);
}

function geminiRun(Transcript $transcript): Transcript
{
    (new TranscribeFinalJob($transcript->id))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));

    return $transcript->refresh();
}

/** @return list<string> "METHOD path" of every request, in order */
function geminiSteps(object $test): array
{
    return array_map(fn (array $c): string => $c['method'].' '.$c['path'].(str_contains($c['url'], 'upload_id=') ? ' (bytes)' : ''), $test->calls);
}

describe('one recording', function () {
    it('uploads the audio, transcribes it with the verbatim rules and a schema, then deletes the upload', function () {
        $this->answers = [['language' => 'ur', 'segments' => [
            ['start_s' => 1.2, 'end_s' => 3.4, 'text' => 'السلام علیکم، آج کی meeting میں invoice کی بات کریں گے۔'],
            ['start_s' => 4.0, 'end_s' => 6.5, 'text' => 'جی ہاں، ہاں، ٹھیک ہے [unclear]'],
        ]]];
        $transcript = geminiTranscript($this);

        geminiRun($transcript);

        expect(geminiSteps($this))->toBe([
            'POST /upload/v1beta/files',
            'POST /upload/v1beta/files (bytes)',
            'POST /v1beta/models/gemini-3.1-pro-preview:generateContent',
            'DELETE /v1beta/files/audio1',
        ]);
        [$start, $bytes, $generate, $delete] = $this->calls;
        expect($start['headers']['x-goog-api-key'][0])->toBe(GEMINI_KEY)
            ->and($start['headers']['X-Goog-Upload-Protocol'][0])->toBe('resumable')
            ->and($start['headers']['X-Goog-Upload-Command'][0])->toBe('start')
            ->and($start['headers']['X-Goog-Upload-Header-Content-Type'][0])->toBe('audio/ogg')
            ->and((int) $start['headers']['X-Goog-Upload-Header-Content-Length'][0])->toBeGreaterThan(0)
            ->and($bytes['headers']['X-Goog-Upload-Command'][0])->toBe('upload, finalize')
            ->and($bytes['headers']['X-Goog-Upload-Offset'][0])->toBe('0')
            ->and($bytes['body'])->toContain('PLAINTEXT-MARKER-0042')
            ->and($delete['headers']['x-goog-api-key'][0])->toBe(GEMINI_KEY);

        $body = json_decode($generate['body'], true);
        $rules = $body['systemInstruction']['parts'][0]['text'];
        expect($rules)->toContain('Never paraphrase, summarise, shorten, correct or translate')
            ->toContain('write [unclear] in its place. Never guess')
            ->toContain('Urdu words in Urdu script')
            ->toContain('English words in English, in Latin letters')
            ->toContain('Never use Devanagari')
            ->toContain('Keep fillers')
            ->and($body['contents'][0]['parts'][0]['fileData'])->toBe(['mimeType' => 'audio/ogg', 'fileUri' => GEMINI_BASE.'/v1beta/files/audio1'])
            ->and($body['contents'][0]['parts'][1]['text'])->toContain('Language: Urdu')
            ->and($body['generationConfig']['responseMimeType'])->toBe('application/json')
            ->and($body['generationConfig']['responseJsonSchema']['properties']['segments']['items']['required'])->toBe(['start_s', 'end_s', 'text'])
            ->and($body['generationConfig']['thinkingConfig'])->toBe(['thinkingLevel' => 'low'])
            ->and($generate['headers']['x-goog-api-key'][0])->toBe(GEMINI_KEY)
            ->and($generate['url'])->not->toContain(GEMINI_KEY);

        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->segments)->toHaveCount(2)
            ->and($transcript->segments[0]['start_ms'])->toBe(1200)
            ->and($transcript->segments[0]['end_ms'])->toBe(3400)
            ->and($transcript->segments[0]['text'])->toContain('meeting')
            ->and($transcript->segments[1]['text'])->toContain('[unclear]')
            ->and($transcript->meta['model'])->toBe('gemini-3.1-pro-preview')
            ->and($transcript->meta['tokens'])->toBe(['prompt' => 1950, 'output' => 120, 'thoughts' => 40])
            ->and($transcript->meta['billed_audio_ms'])->toBe(60_000);
        $entry = UsageLedgerEntry::query()->where('transcript_id', $transcript->id)->sole();
        expect($entry->provider)->toBe('gemini')
            ->and((int) $entry->billed_ms)->toBe(60_000)
            ->and(round((float) $entry->cost_usd, 6))->toBe(round(60_000 / 3_600_000 * 0.23, 6));
    });

    it('deletes the upload when the transcription request fails', function () {
        $this->generateStatus = 503;
        $transcript = geminiTranscript($this, 'en');

        geminiRun($transcript);

        expect(geminiSteps($this))->toBe([
            'POST /upload/v1beta/files',
            'POST /upload/v1beta/files (bytes)',
            'POST /v1beta/models/gemini-3.1-pro-preview:generateContent',
            'DELETE /v1beta/files/audio1',
        ])->and($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('provider_rejected');
    });

    it('deletes the upload when Gemini answers with something that is not a transcript', function () {
        $this->answers = [['note' => 'I cannot help with that']];
        $transcript = geminiTranscript($this, 'en');

        geminiRun($transcript);

        expect(end($this->calls)['method'])->toBe('DELETE')
            ->and($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error)->toContain('Gemini did not answer with a transcript');
    });

    it('needs its key: without one nothing is sent and the reason says so', function () {
        app(CredentialVault::class)->forget('gemini', 'api_key');
        $transcript = geminiTranscript($this, 'en');

        geminiRun($transcript);

        expect($this->calls)->toBe([])
            ->and($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('provider_not_configured');
    });

    it('runs Hindi letters once more as Urdu, then flags the lines that are still Hindi', function () {
        $hindi = ['language' => 'hi', 'segments' => [['start_s' => 0.5, 'end_s' => 2.0, 'text' => 'आप कल की मीटिंग रीशेड्यूल कर दें और मुझे ईमेल भेज दें ताकि मैं इनवॉइस तैयार कर सकूं']]];
        $this->answers = [$hindi, $hindi];
        $transcript = geminiTranscript($this, null);

        geminiRun($transcript);
        expect($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->language)->toBe('ur')
            ->and($transcript->meta['forced_reason'])->toBe('devanagari');
        Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job): bool => $job->transcriptId === $transcript->id);

        geminiRun($transcript);

        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->segments[0]['flag'])->toBe('wrong_script')
            ->and($transcript->segments[0]['text'])->toBe(TranscriptStore::WRONG_SCRIPT_TEXT)
            ->and($transcript->meta['script_warning'])->toBe('devanagari')
            ->and(collect($this->calls)->where('method', 'DELETE')->count())->toBe(2);
    });
});

describe('long recordings and the schema', function () {
    it('sends each chunk on its own, places its lines on the recording\'s timeline and deletes every upload', function () {
        geminiTwoChunks();
        $this->answers = [
            ['language' => 'en', 'segments' => [['start_s' => 10.0, 'end_s' => 12.0, 'text' => 'First part.']]],
            ['language' => 'en', 'segments' => [['start_s' => 5.0, 'end_s' => 7.0, 'text' => 'Second part.']]],
        ];
        $transcript = geminiTranscript($this, 'en');

        geminiRun($transcript);

        expect(collect($this->calls)->filter(fn (array $c): bool => str_contains($c['url'], 'upload_id='))->pluck('body')->all())
            ->toBe(['chunk 0-501', 'chunk 501-900'])
            ->and(collect($this->calls)->where('method', 'DELETE')->pluck('path')->all())->toBe(['/v1beta/files/audio1', '/v1beta/files/audio2'])
            ->and($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and(array_column($transcript->segments, 'start_ms'))->toBe([10_000, 506_000])
            ->and($transcript->meta['chunks'])->toBe(2)
            ->and($transcript->meta['billed_audio_ms'])->toBe(900_000);
        $headers = collect($this->calls)->filter(fn (array $c): bool => $c['path'] === '/upload/v1beta/files' && ! str_contains($c['url'], 'upload_id='))->pluck('headers')->all();
        expect($headers[0]['X-Goog-Upload-Header-Content-Type'][0])->toBe('audio/flac');
    });

    it('maps Gemini\'s segments to ours: empty lines dropped, times kept inside the chunk, Urdu letter forms', function () {
        $segments = GeminiDriver::toSegments([
            ['start_s' => 2.0, 'end_s' => 4.5, 'text' => '  كيا  حال  ہے  '],
            ['start_s' => 5.0, 'end_s' => 6.0, 'text' => '   '],
            ['start_s' => 'x', 'text' => 'No times.'],
            ['start_s' => 80.0, 'end_s' => 95.0, 'text' => 'Past the end.'],
        ], ['start' => 600.0, 'end' => 660.0], 2, 2, 'ur');

        expect($segments)->toHaveCount(3)
            ->and($segments[0]->channel)->toBe(2)
            ->and($segments[0]->speaker)->toBe('Client')
            ->and($segments[0]->startMs)->toBe(602_000)
            ->and($segments[0]->endMs)->toBe(604_500)
            ->and($segments[0]->text)->toBe('کیا حال ہے')
            ->and($segments[1]->startMs)->toBe(600_000)
            ->and($segments[2]->startMs)->toBe(660_000)
            ->and($segments[2]->endMs)->toBe(661_000);
    });

    it('uses the model chosen in Settings, and the current Pro model when the setting is not a model id', function () {
        $this->answers = [['language' => 'en', 'segments' => [['start_s' => 0.0, 'end_s' => 1.0, 'text' => 'Hi.']]]];
        app(SettingsRepository::class)->set('gemini.model', 'gemini-4-argon');

        geminiRun(geminiTranscript($this, 'en'));

        expect(collect($this->calls)->pluck('path')->all())->toContain('/v1beta/models/gemini-4-argon:generateContent')
            ->and(GeminiDriver::modelOr('../../files'))->toBe('gemini-3.1-pro-preview')
            ->and(GeminiDriver::modelOr('gpt-5'))->toBe('gemini-3.1-pro-preview')
            ->and(GeminiDriver::modelOr(null))->toBe(GeminiDriver::DEFAULT_MODEL);
    });
});

describe('an engine like the others', function () {
    it('is a choice for every language, not the first one on any list by default', function () {
        $router = app(LanguageRouter::class);

        expect(LanguageRouter::FINAL_SUPPORT['gemini'])->toContain('ur')->toContain('en')
            ->and(collect(LanguageRouter::DEFAULT_FINAL_ROUTES)->map(fn (array $list): ?string => $list[0] ?? null)->all())->not->toContain('gemini')
            ->and($router->candidates('ur'))->toContain(SttProviderKey::Gemini)
            ->and($router->configured(SttProviderKey::Gemini))->toBeTrue()
            ->and(SttProviderKey::Gemini->getLabel())->toBe('Google Gemini');

        app(CredentialVault::class)->forget('gemini', 'api_key');
        expect(app(LanguageRouter::class)->candidates('ur'))->not->toContain(SttProviderKey::Gemini);
    });

    it('keeps its key on API Credentials like the others', function () {
        expect(CredentialVault::FIELDS['gemini'])->toBe(['api_key' => 'API key']);
    });
});
