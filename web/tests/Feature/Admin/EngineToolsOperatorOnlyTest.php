<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\CredentialVault;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(AdminTestHelpers::class);

/*
| W-002 (v1.8.1): the recording page's tools that pick a speech engine by name (Compare engines, Engine results with
| Use this version, Transcribe with another engine, the Accuracy Lab reference) and the engine names in Compare versions
| are the server's operator's alone. Anyone who can edit a recording re-transcribes by language; the server picks.
*/

/** The engine tools by action name. */
const ENGINE_TOOLS = ['compare_engines', 'engine_results', 'second_engine', 'accuracy_reference'];

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('app');
    Queue::fake();
    foreach (['assemblyai', 'elevenlabs', 'soniox'] as $provider) {
        app(CredentialVault::class)->put($provider, 'api_key', $provider.'_key_1234567890');
    }
});

afterEach(fn () => Fx::cleanupDisks());

/** A finished recording of [$owner] with a comparison run and a failed second version, by engines with names. */
function engineToolsRecording(User $owner): CaptureSession
{
    $session = Fx::session($owner, null, ['title' => 'Call with Omar', 'language' => 'en', 'status' => 'completed']);
    Fx::readyRecording($session);
    Fx::transcript($session, 'We agreed on Friday.');
    $session->transcripts()->create(['kind' => TranscriptKind::Trial, 'provider' => 'soniox', 'status' => TranscriptStatus::Completed, 'language' => 'en',
        'text' => 'We agreed on Friday.', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'We agreed on Friday.']],
        'meta' => ['model' => 'stt-async-v5']]);
    $session->transcripts()->create(['kind' => TranscriptKind::Alternate, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Failed, 'language' => 'en',
        'error' => 'ElevenLabs reported an error.', 'error_code' => 'provider_rejected']);

    return $session->fresh();
}

it('hides the engine tools from a workspace\'s owner, who re-transcribes by language', function () {
    $owner = Fx::user();
    $session = engineToolsRecording($owner);
    $this->actingAs($owner, 'web');

    $page = Livewire::test(ViewCaptureSession::class, ['record' => $session->id]);
    foreach (ENGINE_TOOLS as $tool) {
        $page->assertActionHidden($tool);
    }
    $page->assertActionVisible('retranscribe_as')->assertActionVisible('compare');

    // Asking the page for them anyway mounts nothing, so nothing runs.
    $before = $session->transcripts()->count();
    foreach (['compare_engines', 'second_engine', 'engine_results'] as $tool) {
        $page->call('mountAction', $tool)->assertSet('mountedActions', []);
    }
    expect($session->transcripts()->count())->toBe($before);
    Queue::assertNotPushed(TranscribeFinalJob::class);

    // Nor can the runs be used or made the default from the page's methods.
    $trial = $session->trials()->sole();
    expect(fn () => $page->instance()->useEngineRun($trial->id))->toThrow(HttpException::class)
        ->and(fn () => $page->instance()->pickEngine($trial->id))->toThrow(HttpException::class)
        ->and($session->finalTranscript()->first()->provider)->toBe('assemblyai');
});

it('compares versions for a workspace\'s owner without naming the engines', function () {
    $owner = Fx::user();
    $session = engineToolsRecording($owner);
    $this->actingAs($owner, 'web');

    $html = $this->modalHtml(Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->mountAction('compare'));

    expect($html)->toContain('The second version is failed.')
        ->not->toContain('ElevenLabs')->not->toContain('AssemblyAI');
});

it('keeps every engine tool for the server\'s operator', function () {
    $operator = Fx::superAdmin();
    $session = engineToolsRecording($operator);
    $this->actingAs($operator, 'web');

    $page = Livewire::test(ViewCaptureSession::class, ['record' => $session->id]);
    foreach (ENGINE_TOOLS as $tool) {
        $page->assertActionVisible($tool);
    }
    expect($this->modalHtml(Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->mountAction('compare')))
        ->toContain('The second version (ElevenLabs Scribe) is failed.');

    $page->callAction('second_engine', data: ['provider' => 'soniox', 'language' => 'en'])->assertNotified('Second version queued');
    $trial = $session->trials()->sole();
    $page->call('useEngineRun', $trial->id)->assertNotified('That version is now the transcript');
    expect($session->finalTranscript()->first()->id)->toBe($trial->id);
    Queue::assertPushed(TranscribeFinalJob::class);
});
