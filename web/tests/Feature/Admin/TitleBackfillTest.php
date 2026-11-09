<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Titles\AutoTitles;
use App\Services\Titles\GenericTitles;
use App\Services\Titles\TitleBackfill;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/* Automatic titles for older recordings (since v1.6, P5.3): `echoledger:backfill-titles`, reversible and idempotent. */

beforeEach(function () {
    Storage::fake('local');
    $this->owner = Fx::user(['email' => 'owner@example.com']);
});

/** A recording of [$user] with a finished transcript saying [$words] (null: still being transcribed). */
function titledRecording(User $user, array $session, ?string $words = 'Mujhe laptop chahiye solah GB wala ya battees GB wala kal tak bata dena'): CaptureSession
{
    $capture = CaptureSession::factory()->create(['user_id' => $user->id, 'workspace_id' => $user->current_workspace_id, ...$session]);
    Transcript::query()->create([
        'capture_session_id' => $capture->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq', 'language' => 'ur',
        'status' => $words === null ? TranscriptStatus::Processing : TranscriptStatus::Completed,
        'text' => (string) $words, 'segments' => $words === null ? [] : [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 4000, 'text' => $words]],
    ]);

    return $capture->fresh();
}

/** @return array<string, CaptureSession> the test data, one recording per case */
function backfillData(User $owner): array
{
    return [
        'untitled' => titledRecording($owner, ['source_app' => 'voice_note', 'title' => null]),
        'generic' => titledRecording($owner, ['source_app' => 'whatsapp', 'title' => 'WhatsApp call'], 'Invoice for March is ready please check it today'),
        'file' => titledRecording($owner, ['source_app' => 'voice_note', 'title' => 'PTT-20261108-WA0012'], 'Kal subah site visit hai'),
        'person' => titledRecording($owner, ['source_app' => 'voice_note', 'title' => 'Groceries']),
        'auto' => titledRecording($owner, ['source_app' => 'voice_note', 'title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true]),
        'caller_generic' => titledRecording($owner, ['source_app' => 'whatsapp', 'title' => 'WhatsApp call', 'caller_name' => 'Omar Khan']),
        'caller' => titledRecording($owner, ['source_app' => 'whatsapp', 'title' => null, 'caller_name' => 'Omar Khan']),
        'transcribing' => titledRecording($owner, ['source_app' => 'voice_note', 'title' => 'Voice note'], null),
        'silent' => titledRecording($owner, ['source_app' => 'voice_note', 'title' => null], '   '),
    ];
}

it('knows the titles nobody chose', function (?string $title, bool $generic) {
    expect(GenericTitles::is($title))->toBe($generic);
})->with([
    'none' => [null, true], 'empty' => ['  ', true], 'voice note' => ['Voice note', true], 'whatsapp call' => ['whatsapp CALL', true],
    'phone call' => ['Phone call', true], 'recording' => ['Recording', true], 'imported file' => ['Imported file', true],
    'whatsapp file' => ['PTT-20261108-WA0012', true], 'whatsapp audio' => ['AUD-20261108-WA0003', true],
    'whatsapp export' => ['WhatsApp Audio 2026-11-08 at 10.15.32 PM', true], 'recorder' => ['Voice 001', true], 'iphone' => ['New Recording 3', true],
    'a person\'s' => ['Groceries', false], 'with a number' => ['Call with Omar 2', false], 'a name' => ['Kickoff call with Acme', false],
    'a sentence with voice' => ['Voice note about the invoice', false],
]);

it('lists in a dry run what it would change, and changes nothing', function () {
    $data = backfillData($this->owner);

    $this->artisan('echoledger:backfill-titles', ['--dry-run' => true])
        ->expectsOutputToContain('Would change 4 recordings. Left alone: 1 without a finished transcript, 1 with no words, 1 showing the caller\'s name.')
        ->expectsOutputToContain('Dry run: nothing was changed.')
        ->doesntExpectOutputToContain('Mujhe laptop')
        ->assertSuccessful();

    foreach ($data as $session) {
        expect($session->fresh()->title)->toBe($session->title);
    }
    expect(Storage::disk('local')->allFiles(TitleBackfill::LOG_DIRECTORY))->toBe([]);
});

it('titles generic recordings from their first words, leaves a person\'s title alone and logs it without the words', function () {
    $data = backfillData($this->owner);

    $this->artisan('echoledger:backfill-titles')->expectsOutputToContain('Changed 4 recordings.')->expectsOutputToContain('To undo:')->assertSuccessful();

    expect($data['untitled']->fresh())->title->toBe('Mujhe laptop chahiye solah GB wala ya battees')->title_auto->toBeTrue()
        ->and($data['generic']->fresh())->title->toBe('Invoice for March is ready please check it')->title_auto->toBeTrue()
        ->and($data['file']->fresh()->title)->toBe('Kal subah site visit hai')
        ->and($data['caller_generic']->fresh())->title->toBeNull()->title_auto->toBeFalse()
        ->and($data['caller_generic']->fresh()->displayTitle())->toBe('Call with Omar Khan')
        ->and($data['person']->fresh()->title)->toBe('Groceries')
        ->and($data['auto']->fresh()->title)->toBe('Laptop — 16 vs 32 GB')
        ->and($data['caller']->fresh()->title)->toBeNull()
        ->and($data['transcribing']->fresh()->title)->toBe('Voice note')
        ->and($data['silent']->fresh()->title)->toBeNull();

    $logs = Storage::disk('local')->allFiles(TitleBackfill::LOG_DIRECTORY);
    expect($logs)->toHaveCount(1);
    $log = (string) Storage::disk('local')->get($logs[0]);
    expect($log)->not->toContain('Mujhe')->not->toContain('Invoice')->not->toContain('site visit')
        ->toContain($data['generic']->id)->toContain('WhatsApp call')->toContain('PTT-20261108-WA0012');
});

it('changes nothing the second time', function () {
    backfillData($this->owner);
    $this->artisan('echoledger:backfill-titles')->assertSuccessful();

    $this->artisan('echoledger:backfill-titles')->expectsOutputToContain('Changed 0 recordings.')->assertSuccessful();
    expect(Storage::disk('local')->allFiles(TitleBackfill::LOG_DIRECTORY))->toHaveCount(1);
});

it('puts every title back from the log, except one a person changed since', function () {
    $data = backfillData($this->owner);
    $before = collect($data)->map(fn (CaptureSession $s): array => [$s->title, $s->title_auto])->all();
    $this->artisan('echoledger:backfill-titles')->assertSuccessful();
    $log = basename(Storage::disk('local')->allFiles(TitleBackfill::LOG_DIRECTORY)[0]);
    $data['file']->fresh()->forceFill(['title' => 'Site visit', 'title_auto' => false])->save();

    $this->artisan('echoledger:backfill-titles', ['--undo' => $log])->expectsOutputToContain('Put back 3 titles. Left 1')->assertSuccessful();

    foreach (['untitled', 'generic', 'caller_generic', 'person', 'auto'] as $key) {
        expect([$data[$key]->fresh()->title, $data[$key]->fresh()->title_auto])->toBe($before[$key]);
    }
    expect($data['file']->fresh()->title)->toBe('Site visit');

    // And again: the backfill finds the same recordings (up → down → up).
    $this->artisan('echoledger:backfill-titles')->expectsOutputToContain('Changed 3 recordings.')->assertSuccessful();
});

it('refuses a log it did not write', function (string $name) {
    $this->artisan('echoledger:backfill-titles', ['--undo' => $name])->expectsOutputToContain('No title backfill log')->assertFailed();
})->with(['../../.env', 'nothing.json', '20261109-120000-abcdef.json']);

it('only touches one person\'s recordings with --user, and never another person\'s', function () {
    $other = Fx::user(['email' => 'other@example.com']);
    $mine = titledRecording($this->owner, ['title' => 'Voice note', 'source_app' => 'voice_note']);
    $theirs = titledRecording($other, ['title' => 'Voice note', 'source_app' => 'voice_note']);

    $this->artisan('echoledger:backfill-titles', ['--user' => 'owner@example.com'])->expectsOutputToContain('Changed 1 recording.')->assertSuccessful();
    expect($mine->fresh()->title_auto)->toBeTrue()->and($theirs->fresh()->title)->toBe('Voice note');

    $this->artisan('echoledger:backfill-titles', ['--user' => 'nobody@example.com'])->assertFailed();
});

it('leaves recordings in the bin alone', function () {
    $gone = titledRecording($this->owner, ['title' => null, 'source_app' => 'voice_note']);
    $gone->delete();

    $this->artisan('echoledger:backfill-titles')->expectsOutputToContain('Changed 0 recordings.')->assertSuccessful();
    expect(CaptureSession::withTrashed()->find($gone->id)->title)->toBeNull();
});

it('shows the new titles only when asked', function () {
    titledRecording($this->owner, ['title' => null, 'source_app' => 'voice_note'], 'Invoice for March');

    $this->artisan('echoledger:backfill-titles', ['--dry-run' => true])->doesntExpectOutputToContain('Invoice for March')->expectsOutputToContain('first words (17 characters)');
    $this->artisan('echoledger:backfill-titles', ['--dry-run' => true, '--show-titles' => true])->expectsOutputToContain('Invoice for March');
});

it('gives a shared note named after its file an automatic title from now on', function () {
    $session = titledRecording($this->owner, ['title' => 'PTT-20261108-WA0012', 'source_app' => 'voice_note'], 'Kal subah site visit hai');
    $person = titledRecording($this->owner, ['title' => 'Site visit notes', 'source_app' => 'voice_note'], 'Kal subah site visit hai');

    expect(AutoTitles::wanted($session))->toBeTrue()->and(AutoTitles::wanted($person))->toBeFalse();
    app(AutoTitles::class)->make($session);
    app(AutoTitles::class)->make($person);

    expect($session->fresh())->title->toBe('Kal subah site visit hai')->title_auto->toBeTrue()
        ->and($person->fresh()->title)->toBe('Site visit notes');
});
