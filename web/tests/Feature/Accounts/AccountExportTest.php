<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Mail\ExportReadyMail;
use App\Models\AccountExport;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Transcript;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\RecordingCipher;
use App\Services\Screens\ScreenStore;
use App\Services\Views\TranscriptViews;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/../Api/Support/screen_helpers.php';

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

/** A library with one of everything the export carries; returns what the test checks against. */
function exportLibrary(object $test, $device): array
{
    $audio = Fixtures::audio(5000, 'EXPORT-AUDIO-MARKER');
    $project = Client::factory()->create(['user_id' => $device->user_id, 'name' => 'Acme Builders', 'notes' => 'Call before noon.']);
    $session = $test->makeReadySession($device, $audio, ['title' => 'Budget call', 'client_id' => $project->id, 'status' => 'completed']);
    $segments = [Fixtures::segment(['text' => 'Aap kaise hain', 'start_ms' => 0, 'end_ms' => 1500])];
    $transcript = $session->transcripts()->create(['kind' => 'final', 'provider' => 'groq', 'status' => 'completed', 'language' => 'ur', 'text' => 'Aap kaise hain', 'segments' => $segments]);
    $transcript->forceFill(['meta' => ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'roman' => ['ROMAN-VIEW-LINE'], 'urdu' => ['URDU-VIEW-LINE'], 'english' => ['ENGLISH-VIEW-LINE']]]])->save();
    $picture = screenPicture();
    $plain = tempnam(sys_get_temp_dir(), 'scr');
    file_put_contents($plain, $picture);
    app(ScreenStore::class)->add($session, $plain, ScreenStore::sniff($plain) ?? [], ['at_ms' => 65000, 'width' => 1280, 'height' => 720, 'trigger' => 'manual', 'sha256' => hash('sha256', $picture)]);
    @unlink($plain);
    CallNote::query()->create(['workspace_id' => $session->workspace_id, 'client_id' => $project->id, 'capture_session_id' => $session->id, 'status' => 'ready', 'language' => 'en', 'sections' => ['decisions' => [['text' => 'NOTE-DECISION']]]]);
    $doc = tempnam(sys_get_temp_dir(), 'doc');
    file_put_contents($doc, 'PROJECT-FILE-CONTENT');
    app(ClientWorkspace::class)->storeFile($project, $doc, 'scope.txt', 'text/plain', 20, $device->user_id);
    @unlink($doc);

    return ['audio' => $audio, 'picture' => $picture, 'session' => $session];
}

/** Downloads the export's link and opens the ZIP. */
function openExport(object $test, string $url): ZipArchive
{
    $response = $test->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    return $zip;
}

it('makes one ZIP of the account: audio, every view as .txt and .json, screens, notes, project files and a manifest', function () {
    $user = Fx::user(['email' => 'keeper@example.test']);
    [$device, $token] = $this->enroll($user);
    $made = exportLibrary($this, $device);
    $other = Fx::user();
    CaptureSession::factory()->create(['user_id' => $other->id, 'title' => 'SOMEONE-ELSES-CALL']);

    $started = $this->asToken($token)->postJson('/api/account/export')->assertStatus(202);
    expect($started->json('status'))->toBeIn(['making', 'ready']);
    $status = $this->asToken($token)->getJson('/api/account/export/'.$started->json('id'))->assertOk()
        ->assertJsonPath('status', 'ready')->assertJsonPath('id', $started->json('id'));
    $url = (string) $status->json('url');
    expect($url)->toContain('/account/export/')->toContain('signature=')
        ->and($status->json('expires_at'))->not->toBeNull();
    Mail::assertQueued(ExportReadyMail::class, fn (ExportReadyMail $mail): bool => $mail->hasTo('keeper@example.test') && $mail->url === $url);

    // Stored encrypted, with nothing readable on the disk.
    $export = AccountExport::query()->findOrFail($started->json('id'));
    $stored = (string) Storage::disk('recordings')->get((string) $export->path);
    expect(substr($stored, 0, 4))->toBe(RecordingCipher::MAGIC)->and($stored)->not->toContain('EXPORT-AUDIO-MARKER');

    app('auth')->forgetGuards();
    $zip = openExport($this, $url);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    $folder = 'recordings/'.$made['session']->started_at->format('Y-m-d').' Budget call';
    expect($names)->toContain('manifest.json', "{$folder}/audio-primary.ogg", "{$folder}/transcript.txt", "{$folder}/transcript.json",
        "{$folder}/transcript-roman.txt", "{$folder}/transcript-roman.json", "{$folder}/transcript-urdu.txt", "{$folder}/transcript-english.json",
        "{$folder}/screens/001-1-05.webp", "{$folder}/screens.json", "{$folder}/notes.json",
        'projects/Acme Builders/notes.txt', 'projects/Acme Builders/files/scope.txt');
    expect($zip->getFromName("{$folder}/audio-primary.ogg"))->toBe($made['audio'])
        ->and($zip->getFromName("{$folder}/screens/001-1-05.webp"))->toBe($made['picture'])
        ->and($zip->getFromName("{$folder}/transcript-roman.txt"))->toContain('ROMAN-VIEW-LINE')
        ->and($zip->getFromName("{$folder}/transcript.txt"))->toContain('Aap kaise hain')
        ->and($zip->getFromName("{$folder}/notes.json"))->toContain('NOTE-DECISION')
        ->and($zip->getFromName('projects/Acme Builders/files/scope.txt'))->toBe('PROJECT-FILE-CONTENT');
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    expect($manifest['format'])->toBe('echoledger-account-export')
        ->and($manifest['account']['email'])->toBe('keeper@example.test')
        ->and($manifest['recordings'])->toHaveCount(1)
        ->and($manifest['recordings'][0]['project'])->toBe('Acme Builders')
        ->and($manifest['projects'][0]['name'])->toBe('Acme Builders');
    $all = '';
    foreach ($names as $name) {
        $all .= (string) $zip->getFromName($name);
    }
    expect($all)->not->toContain('SOMEONE-ELSES-CALL');
    $zip->close();
    expect(is_dir(storage_path('app/private/export-work/'.$export->id)))->toBeFalse();
});

it('keeps one export at a time, and answers 404 for another account\'s', function () {
    $user = Fx::user();
    [, $token] = $this->enroll($user);
    $making = AccountExport::query()->create(['user_id' => $user->id, 'status' => 'making']);

    $this->asToken($token)->postJson('/api/account/export')->assertStatus(202)->assertJsonPath('id', $making->id)->assertJsonPath('status', 'making')
        ->assertJsonPath('url', null);
    expect(AccountExport::query()->count())->toBe(1);

    $theirs = AccountExport::query()->create(['user_id' => Fx::user()->id, 'status' => 'making']);
    $this->asToken($token)->getJson('/api/account/export/'.$theirs->id)->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('deletes the ZIP after 24 hours, after which the link no longer works', function () {
    $user = Fx::user();
    [$device, $token] = $this->enroll($user);
    $this->makeReadySession($device, Fixtures::audio(3000));
    $id = $this->asToken($token)->postJson('/api/account/export')->json('id');
    $export = AccountExport::query()->findOrFail($id);
    $url = (string) $export->downloadUrl();
    expect(Storage::disk('recordings')->exists((string) $export->path))->toBeTrue();

    $this->travel(AccountExport::HOURS)->hours();
    $this->travel(1)->minutes();
    app('auth')->forgetGuards();
    $this->get($url)->assertForbidden();
    Artisan::call('echoledger:prune-exports');

    expect(AccountExport::query()->find($id))->toBeNull()
        ->and(Storage::disk('recordings')->exists((string) $export->path))->toBeFalse();
});

it('starts an export from the profile and offers the download once it is ready', function () {
    $user = Fx::user();
    $this->actingAs($user, 'web');

    Livewire::test(EditProfile::class)->assertSee('Your data')
        ->callAction(TestAction::make('exportData')->schemaComponent('data-actions', schema: 'content'))->assertNotified('Your export is being made');

    $export = AccountExport::query()->where('user_id', $user->id)->sole();
    expect($export->status)->toBe('ready');
    Livewire::test(EditProfile::class)->assertSee('Your export is ready until')->assertSee('Download my export')->assertSee('Delete my account');
});

it('includes a recording\'s other engines and live captions, never trial runs', function () {
    $user = Fx::user();
    [$device, $token] = $this->enroll($user);
    $session = $this->makeReadySession($device, Fixtures::audio(3000), ['title' => 'Engines']);
    $segment = [Fixtures::segment(['text' => 'Hello there'])];
    $session->transcripts()->create(['kind' => 'live', 'provider' => 'assemblyai', 'status' => 'completed', 'text' => 'LIVE-TEXT', 'segments' => [Fixtures::segment(['text' => 'LIVE-TEXT'])]]);
    Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => 'trial', 'provider' => 'soniox', 'status' => 'completed', 'text' => 'TRIAL-TEXT', 'segments' => [Fixtures::segment(['text' => 'TRIAL-TEXT'])]]);
    $session->transcripts()->create(['kind' => 'alternate', 'provider' => 'elevenlabs', 'status' => 'completed', 'text' => 'Hello there', 'segments' => $segment]);

    $id = $this->asToken($token)->postJson('/api/account/export')->json('id');
    app('auth')->forgetGuards();
    $zip = openExport($this, (string) AccountExport::query()->findOrFail($id)->downloadUrl());
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }

    expect(implode("\n", $names))->toContain('/live-captions.txt')->toContain('/transcript-elevenlabs-');
    $all = '';
    foreach ($names as $name) {
        $all .= (string) $zip->getFromName($name);
    }
    expect($all)->toContain('LIVE-TEXT')->not->toContain('TRIAL-TEXT');
});
