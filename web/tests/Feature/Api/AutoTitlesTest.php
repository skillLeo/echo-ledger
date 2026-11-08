<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\Titles\AutoTitles;
use App\Services\Titles\TitleWriter;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/* Automatic titles (since v1.5, Phase 1C): an untitled recording is named from what was said; a person's title wins. */

/** A title writer that answers [$title] and counts its calls. */
function fakeTitleWriter(string $title = '"Laptop — 16 vs 32 GB."'): object
{
    $fake = new class($title) implements TitleWriter
    {
        public int $calls = 0;

        public ?string $language = null;

        public function __construct(private readonly string $answer) {}

        public function title(string $transcript, string $language): array
        {
            $this->calls++;
            $this->language = $language;

            return ['title' => $this->answer, 'input_tokens' => 300, 'output_tokens' => 20, 'model' => 'claude-haiku-5-5'];
        }
    };
    app()->instance(TitleWriter::class, $fake);

    return $fake;
}

function finishVoiceNote(object $test, array $session = [], string $text = 'Mujhe laptop chahiye solah GB wala ya battees GB wala, kal tak bata dena'): CaptureSession
{
    $model = $test->makeReadySession($test->device, Fixtures::audio(4000), ['source_app' => 'voice_note', 'language' => 'ur', ...$session]);
    $transcript = $model->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Processing, 'language' => 'ur']);
    app(TranscriptStore::class)->complete($transcript, new NormalizedTranscript('Me: '.$text, [new Segment(1, 'Me', 0, 4000, $text)], 'ur'));

    return $model->refresh();
}

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

it('names an untitled recording with Claude Haiku once its transcript is ready, and says the title is automatic', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $writer = fakeTitleWriter();

    $session = finishVoiceNote($this);

    expect($session->title)->toBe('Laptop — 16 vs 32 GB')->and($session->title_auto)->toBeTrue()
        ->and($writer->calls)->toBe(1)->and($writer->language)->toBe('ur')
        ->and(UsageLedgerEntry::query()->where('use_case', 'auto_title')->where('capture_session_id', $session->id)->count())->toBe(1);
    $this->getJson("/api/sessions/{$session->id}")->assertOk()->assertJsonPath('title', 'Laptop — 16 vs 32 GB')->assertJsonPath('title_auto', true);
    $item = collect($this->getJson('/api/library')->assertOk()->json('data'))->firstWhere('id', $session->id);
    expect($item['title_auto'])->toBeTrue();
});

it('falls back to the first words without a key', function () {
    $writer = fakeTitleWriter();

    $session = finishVoiceNote($this);

    expect($session->title)->toBe('Mujhe laptop chahiye solah GB wala ya battees')->and($session->title_auto)->toBeTrue()
        ->and($writer->calls)->toBe(0);
});

it('never replaces a title a person set, at upload or later, and clearing it asks for the automatic one again', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $writer = fakeTitleWriter();

    $named = finishVoiceNote($this, ['title' => 'Groceries']);
    expect($named->title)->toBe('Groceries')->and($named->title_auto)->toBeFalse()->and($writer->calls)->toBe(0);
    $this->getJson("/api/sessions/{$named->id}")->assertOk()->assertJsonPath('title_auto', false);

    $auto = finishVoiceNote($this);
    $this->patchJson("/api/sessions/{$auto->id}", ['title' => 'My laptop'])->assertOk()->assertJsonPath('data.title', 'My laptop')->assertJsonPath('data.title_auto', false);
    // The transcript made again (a re-transcription) does not touch it.
    app(AutoTitles::class)->make($auto->refresh());
    expect($auto->refresh()->title)->toBe('My laptop');

    $this->patchJson("/api/sessions/{$auto->id}", ['title' => null])->assertOk()->assertJsonPath('data.title', 'Laptop — 16 vs 32 GB')->assertJsonPath('data.title_auto', true);
    expect($writer->calls)->toBe(2);
});

it('keeps the caller\'s name as the title of a call with a known person', function () {
    $writer = fakeTitleWriter();
    $session = finishVoiceNote($this, ['source_app' => 'whatsapp', 'caller_name' => 'Omar Khan']);

    expect($session->title)->toBeNull()->and($session->displayTitle())->toBe('Call with Omar Khan')->and($writer->calls)->toBe(0);
});

it('keeps titles short, on one line, without quotes or a full stop', function () {
    expect(AutoTitles::clean("  \"Invoice for March.\"\n"))->toBe('Invoice for March')
        ->and(mb_strlen(AutoTitles::clean(str_repeat('word ', 30))))->toBeLessThanOrEqual(60)
        ->and(AutoTitles::firstWords('[unclear] hello'))->toBe('[unclear] hello')
        ->and(AutoTitles::plainText(new Transcript(['segments' => [['text' => '[unclear] kal [?meeting] hai']]])))->toBe('kal meeting hai');
});
