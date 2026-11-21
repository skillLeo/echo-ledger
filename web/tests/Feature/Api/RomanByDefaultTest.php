<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\MakeTranscriptViewsJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\Titles\AutoTitles;
use App\Services\Titles\TitleWriter;
use App\Services\Views\SoundCheck;
use App\Services\Views\TranscriptConverter;
use App\Services\Views\TranscriptViews;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * U19: an Urdu (or mixed) recording is Roman Urdu by default. The view counts as being made from the moment the
 * transcript is finished (before its job has started), a conversion can never look busy for ever (it fails after 20
 * quiet minutes, and Try again starts it again), and its automatic title is in Roman Urdu: written so, or written again
 * when the Roman Urdu view is in. A title a person gave is never touched; English and Arabic are left as they were.
 */

/** A finished Urdu voice note with two lines, without views yet (nothing has started them). */
function u19Transcript(object $test, array $session = [], array $transcript = []): Transcript
{
    $model = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => 'ur', 'status' => 'completed', 'source_app' => 'voice_note', ...$session]);

    return $model->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'ur',
        'completed_at' => now(), 'text' => "Me: میٹنگ کا ٹائم\nMe: ٹیکس فارم ٹھیک کرنا ہے", 'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'میٹنگ کا ٹائم'],
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'ٹیکس فارم ٹھیک کرنا ہے'],
        ], ...$transcript]);
}

/** A converter that writes each line's Roman Urdu letter by letter (the views' sound check passes it). */
function u19Converter(): void
{
    app()->instance(TranscriptConverter::class, new class implements TranscriptConverter
    {
        public function convert(array $lines, string $language, array $views): array
        {
            $roman = fn (string $t): string => implode(' ', array_map(SoundCheck::plainRoman(...), SoundCheck::words($t)));

            return [
                'lines' => array_map(fn (string $t): array => array_intersect_key(['roman' => $roman($t), 'english' => 'E: '.$t, 'urdu' => $t], array_flip($views)), $lines),
                'input_tokens' => 10, 'output_tokens' => 20, 'model' => 'claude-opus-5-5',
            ];
        }
    });
}

/** A title writer that answers [$title] and keeps what it was asked. */
function u19Writer(string $title): object
{
    $fake = new class($title) implements TitleWriter
    {
        /** @var list<array{text: string, language: string, roman: bool}> */
        public array $asked = [];

        public function __construct(private readonly string $answer) {}

        public function title(string $transcript, string $language, bool $roman = false): array
        {
            $this->asked[] = ['text' => $transcript, 'language' => $language, 'roman' => $roman];

            return ['title' => $this->answer, 'input_tokens' => 100, 'output_tokens' => 10, 'model' => 'claude-haiku-5-5'];
        }
    };
    app()->instance(TitleWriter::class, $fake);

    return $fake;
}

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
});

it('counts the Roman Urdu view as being made from the moment the transcript is finished, before any job has started', function () {
    Queue::fake();
    $transcript = u19Transcript($this);

    $this->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()
        ->assertJsonPath('status', 'making')->assertJsonPath('default', 'roman')->assertJsonPath('progress', []);
    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('views.status', 'making');
    $item = collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $transcript->capture_session_id);
    expect($item['transcript']['views'])->toBe(['status' => 'making', 'default' => 'roman', 'progress' => []]);

    // It is about to be made, not being made: asking (or autoMake) still starts it, once.
    $this->postJson("/api/transcripts/{$transcript->id}/views")->assertStatus(202)->assertJsonPath('status', 'making');
    app(TranscriptViews::class)->autoMake($transcript->refresh());
    Queue::assertPushed(MakeTranscriptViewsJob::class, 1);
});

it('stops counting it after 20 minutes, without a key or when the recording opens in its original words', function () {
    $transcript = u19Transcript($this);
    $this->travel(21)->minutes();
    expect(app(TranscriptViews::class)->state($transcript->refresh())['status'])->toBe('none')
        ->and(TranscriptViews::rowSummary($transcript)['status'])->toBe('none');
    $this->travelBack();

    $english = u19Transcript($this, ['language' => 'en'], ['language' => 'en', 'text' => 'Me: hello', 'segments' => [['speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'hello']]]);
    expect(app(TranscriptViews::class)->state($english)['status'])->toBe('none')->and($english->refresh()->meta['views'] ?? null)->toBeNull();

    app(SettingsRepository::class)->set('views.default_ur', 'original');
    expect(app(TranscriptViews::class)->state(u19Transcript($this))['status'])->toBe('none');
    app(SettingsRepository::class)->set('views.default_ur', 'roman');

    app(CredentialVault::class)->forget('anthropic', 'api_key');
    expect(app(TranscriptViews::class)->state(u19Transcript($this))['status'])->toBe('none');
});

it('never shows a conversion as busy for ever: 20 quiet minutes read as failed, and Try again starts it again', function () {
    Queue::fake();
    $transcript = u19Transcript($this, [], ['completed_at' => now()->subHour()]);
    $transcript->forceFill(['meta' => ['views' => ['status' => 'making', 'fingerprint' => TranscriptViews::fingerprint($transcript),
        'started_at' => now()->subMinutes(25)->toAtomString(), 'touched_at' => now()->subMinutes(2)->toAtomString(), 'lines_done' => 1, 'lines_total' => 2]]])->save();
    // Lines still moving: still being made, with its share.
    expect(app(TranscriptViews::class)->state($transcript)['status'])->toBe('making')
        ->and(TranscriptViews::rowSummary($transcript)['progress'])->toBe(['roman' => ['done' => 1, 'total' => 2], 'urdu' => ['done' => 1, 'total' => 2], 'english' => ['done' => 1, 'total' => 2]]);

    $this->travel(19)->minutes();
    $this->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', TranscriptViews::TOOK_TOO_LONG)->assertJsonPath('progress', []);
    expect(TranscriptViews::rowSummary($transcript->refresh())['status'])->toBe('failed');

    $this->postJson("/api/transcripts/{$transcript->id}/views")->assertStatus(202)->assertJsonPath('status', 'making');
    Queue::assertPushed(MakeTranscriptViewsJob::class, 1);
});

it('asks for the automatic title of an Urdu call in Roman Urdu, from the Roman Urdu view when it is made', function () {
    $writer = u19Writer('Meeting ka time aur tax form.');
    $transcript = u19Transcript($this);
    $session = CaptureSession::query()->findOrFail($transcript->capture_session_id);

    app(AutoTitles::class)->make($session);
    expect($session->refresh()->title)->toBe('Meeting ka time aur tax form')->and($session->title_auto)->toBeTrue()
        ->and($writer->asked[0]['roman'])->toBeTrue()->and($writer->asked[0]['language'])->toBe('ur')
        ->and($writer->asked[0]['text'])->toContain('میٹنگ');

    // Once the view is made, the title is written from its words.
    u19Converter();
    app(TranscriptViews::class)->make($transcript->refresh());
    $session->forceFill(['title' => null, 'title_auto' => false])->save();
    app(AutoTitles::class)->make($session->refresh());
    expect(end($writer->asked)['text'])->not->toContain('میٹنگ')->toContain('metng ka tam')->and(end($writer->asked)['roman'])->toBeTrue();
});

it('leaves the titles of English and Arabic recordings as they were asked for', function () {
    $writer = u19Writer('Invoice for March');
    $english = u19Transcript($this, ['language' => 'en'], ['language' => 'en', 'text' => 'Me: the invoice', 'segments' => [['speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'the invoice for March']]]);
    app(AutoTitles::class)->make(CaptureSession::query()->findOrFail($english->capture_session_id));
    $arabic = u19Transcript($this, ['language' => 'ar'], ['language' => 'ar']);
    app(AutoTitles::class)->make(CaptureSession::query()->findOrFail($arabic->capture_session_id));

    expect(array_column($writer->asked, 'roman'))->toBe([false, false]);
});

it('writes an automatic title in Urdu letters again in Roman Urdu when the Roman Urdu view is in, never a person\'s own', function () {
    u19Converter();
    $writer = u19Writer('Meeting ka time aur tax form ki correction');
    $auto = u19Transcript($this, ['title' => 'میٹنگ ٹائم اور ٹیکس فارم کی اصلاح', 'title_auto' => true]);
    $own = u19Transcript($this, ['title' => 'میری میٹنگ', 'title_auto' => false, 'title_by_person' => true]);
    $romanAlready = u19Transcript($this, ['title' => 'Tax form', 'title_auto' => true]);

    foreach ([$auto, $own, $romanAlready] as $transcript) {
        app(TranscriptViews::class)->make($transcript);
    }

    expect(CaptureSession::query()->findOrFail($auto->capture_session_id)->title)->toBe('Meeting ka time aur tax form ki correction')
        ->and(CaptureSession::query()->findOrFail($auto->capture_session_id)->title_auto)->toBeTrue()
        ->and(CaptureSession::query()->findOrFail($own->capture_session_id)->title)->toBe('میری میٹنگ')
        ->and(CaptureSession::query()->findOrFail($romanAlready->capture_session_id)->title)->toBe('Tax form')
        ->and($writer->asked)->toHaveCount(1)->and($writer->asked[0]['roman'])->toBeTrue();
    $this->getJson("/api/sessions/{$auto->capture_session_id}")->assertOk()->assertJsonPath('title_source', 'auto');
});

it('takes the Roman Urdu view\'s first words when the writer still answers in Urdu letters', function () {
    u19Converter();
    u19Writer('میٹنگ کا ٹائم');
    $transcript = u19Transcript($this, ['title' => 'میٹنگ ٹائم', 'title_auto' => true]);

    app(TranscriptViews::class)->make($transcript);

    $title = (string) CaptureSession::query()->findOrFail($transcript->capture_session_id)->title;
    expect(AutoTitles::hasUrduLetters($title))->toBeFalse()->and($title)->not->toBe('')->and(mb_strlen($title))->toBeLessThanOrEqual(AutoTitles::MAX);
});

it('writes the titles made in Urdu letters before again with echoledger:roman-titles (a dry run first)', function () {
    $transcript = u19Transcript($this, ['title' => 'میٹنگ ٹائم', 'title_auto' => true]);
    u19Converter();
    u19Writer('میٹنگ');
    app(TranscriptViews::class)->make($transcript); // the writer answered in Urdu letters: the first words were taken
    $session = CaptureSession::query()->findOrFail($transcript->capture_session_id);
    $session->forceFill(['title' => 'میٹنگ ٹائم'])->save();
    u19Writer('Meeting ka time');

    $this->artisan('echoledger:roman-titles', ['--dry-run' => true])->expectsOutputToContain('Would write 1 title again in Roman Urdu')->assertSuccessful();
    expect($session->refresh()->title)->toBe('میٹنگ ٹائم');
    $this->artisan('echoledger:roman-titles')->expectsOutputToContain('Wrote 1 of 1 title again in Roman Urdu')->assertSuccessful();
    expect($session->refresh()->title)->toBe('Meeting ka time');
});

it('gives a Library row its preview in Roman Urdu once that view is made, and says which view with preview_view', function () {
    $transcript = u19Transcript($this);
    $item = fn (): array => collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $transcript->capture_session_id)['transcript'];
    expect($item()['preview'])->toContain('میٹنگ')->and($item()['preview_view'])->toBe('original');

    u19Converter();
    app(TranscriptViews::class)->make($transcript->refresh());
    expect($item()['preview'])->toBe("metng ka tam\nteks farm thek karna he")->and($item()['preview_view'])->toBe('roman');
    $this->getJson("/api/sessions/{$transcript->capture_session_id}")->assertOk()->assertJsonPath('transcript.preview_view', 'roman');

    // Edited after the view was made: the edited words show until the view is made again.
    $this->travel(1)->minutes();
    $transcript->refresh()->forceFill(['edited_at' => now()])->save();
    expect($item()['preview_view'])->toBe('original')->and($item()['preview'])->toContain('میٹنگ');

    // A recording that opens in its original words keeps them.
    $this->travelBack();
    app(SettingsRepository::class)->set('views.default_ur', 'original');
    $transcript->forceFill(['edited_at' => null])->save();
    expect($item()['preview_view'])->toBe('original');
});
