<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Auth\EditProfile;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Support\V2CopyForAi;
use App\Models\CaptureSession;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Entitlements\Entitlements;
use App\Services\InterfaceFlags;
use App\Services\TranscribeLater;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The new Library selection's ⋮ (U9, docs/PLAN_V2_PARITY.md section 3): Mark important / Unmark important by the apps'
 * rule, through the same bulk path as the API (WEB-LIB-031); Transcribe… with the minutes first, starting as the
 * recording page's Transcribe again does (WEB-LIB-029); and Copy for AI, the apps' text on the clipboard (WEB-LIB-030).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->done = Fx::session($this->owner, $this->device, ['title' => 'Kick-off', 'source_app' => SourceApp::Zoom,
        'started_at' => Carbon::parse('2026-11-16 16:30:00'), 'duration_ms' => 60_000]);
    $this->done->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'ur',
        'text' => 'Assalam o alaikum. The proforma is ready.',
        'segments' => [
            ['speaker' => 'A', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Assalam o alaikum.', 'words' => []],
            ['speaker' => 'B', 'start_ms' => 65_000, 'end_ms' => 67_000, 'text' => 'The proforma is ready.',
                'words' => [['start_ms' => 65_000, 'end_ms' => 65_300, 'text' => 'The', 'confidence' => 0.99], ['start_ms' => 65_300, 'end_ms' => 65_900, 'text' => 'proforma', 'confidence' => 0.2]]],
        ]]);
    $this->done->forceFill(['speaker_names' => ['A' => 'Me', 'B' => 'Habib']])->save();
    $this->waiting = Fx::session($this->owner, $this->device, ['title' => 'Untranscribed call', 'source_app' => SourceApp::PhoneCall,
        'started_at' => Carbon::parse('2026-11-17 08:00:00'), 'duration_ms' => 150_000, 'status' => SessionStatus::Completed]);
    Fx::readyRecording($this->waiting, 'audio of the call');
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

/** Puts the person's workspace on [$plan] with [$minutes] transcription minutes a month (Pro: transcription on EchoLedger). */
function selectionPlan(object $test, string $plan, ?int $minutes): void
{
    Plan::query()->where('key', $plan)->update(['monthly_minutes' => $minutes]);
    Workspace::query()->whereKey($test->owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', $plan)->value('id'),
        'plan_expires_at' => $plan === 'free' ? null : now()->addDays(30)]);
    app(Entitlements::class)->forget();
}

/** Runs the starts Transcribe… put on the queue (Queue::fake holds them), as a worker would. */
function selectionRunQueuedStarts(): int
{
    $jobs = Queue::pushed(CallQueuedClosure::class);
    $jobs->each(fn (CallQueuedClosure $job) => $job->handle(app()));

    return $jobs->count();
}

/** The notices sent so far, as JSON. */
function selectionMoreNotices(): string
{
    app(NotificationMailbox::class)->flush();
    $notices = new Notifications;
    $notices->mount();
    $notices->pullNotificationsFromSession();

    return (string) json_encode($notices->notifications->toArray(), JSON_UNESCAPED_UNICODE);
}

it('marks and unmarks important through the API\'s bulk path (LibraryOrganiser::bulk, which also moves updated_at for the apps\' sync)', function () {
    $ids = [$this->done->id, $this->waiting->id];
    Carbon::setTestNow(Carbon::parse('2026-11-17 12:00:00', 'UTC'));

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('importantInMenu', $ids)->assertHasNoTableBulkActionErrors();
    $marked = CaptureSession::query()->whereIn('id', $ids)->get();
    expect($marked->pluck('important')->unique()->all())->toBe([true])
        ->and($marked->every(fn (CaptureSession $s): bool => $s->updated_at->eq(Carbon::parse('2026-11-17 12:00:00'))))->toBeTrue();
    expect(selectionMoreNotices())->toContain('2 recordings updated');

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('unimportantInMenu', [$this->done->id]);
    expect($this->done->fresh()->important)->toBeFalse()->and($this->waiting->fresh()->important)->toBeTrue();
});

it('offers Mark and Unmark important by the apps\' rule, read from the rows (the Node test of the rule)', function () {
    $html = Livewire::test(ListCaptureSessions::class)->html();
    expect($html)->toContain("window.elV2Selection.shows('important', selectedRecords, isTrackingDeselectedRecords)")
        ->toContain("window.elV2Selection.shows('unimportant', selectedRecords, isTrackingDeselectedRecords)");
    expect((string) $this->get('/app/sessions')->getContent())->toContain('window.elV2Selection = window.elV2Selection ||');

    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/library-selection-core.test.mjs']);
    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

it('says the minutes and the minutes left before Transcribe starts, then starts what can be transcribed', function () {
    selectionPlan($this, 'pro', 120);
    Queue::fake();
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereIn('id', [$this->done->id, $this->waiting->id])->get());
    // 2:30 on the recording's two channels is billed as 5 minutes (rounded up); the transcribed one is not counted.
    expect($plan['minutes'])->toBe(5)->and($plan['left'])->toBe(120)->and($plan['can'])->toBeTrue()
        ->and($plan['words'])->toBe('About 5 minutes of the 120 minutes left this month.')
        ->and(array_map(fn (CaptureSession $r): string => (string) $r->id, $plan['ready']))->toBe([(string) $this->waiting->id]);

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$this->done->id, $this->waiting->id]);
    expect(selectionMoreNotices())->toContain('Transcribing 1 recording · 1 skipped (already transcribed or no audio)');
    // The start is queued, never run inside the web request; the worker starts it as the recording page does.
    expect($this->waiting->fresh()->finalTranscript)->toBeNull()->and(selectionRunQueuedStarts())->toBe(1);
    expect($this->waiting->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Pending)
        ->and($this->waiting->fresh()->status)->toBe(SessionStatus::Processing);
});

it('will not start Transcribe when the month can\'t cover it, nor more than 50 at a time', function () {
    selectionPlan($this, 'pro', 2);
    Queue::fake();
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($this->waiting->id)->get());
    expect($plan['can'])->toBeFalse()
        ->and($plan['words'])->toBe('About 5 minutes of the 2 minutes left this month. That is more than is left, so nothing will start. Pick fewer recordings.');

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$this->waiting->id]);
    expect($this->waiting->fresh()->finalTranscript)->toBeNull();

    // Nothing it can start (transcribed, or no audio here): it says so and won't start.
    $none = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($this->done->id)->get());
    expect($none['can'])->toBeFalse()->and($none['words'])->toBe('Nothing here can be transcribed: each one is already transcribed or has no audio.');

    $many = collect(range(1, 51))->map(fn (int $i): CaptureSession => Fx::session($this->owner, $this->device, ['title' => "Call {$i}"]));
    $tooMany = new Collection($many->all());
    expect(CaptureSessionsTable::transcribePlan($tooMany)['words'])->toBe('Up to 50 at a time: pick fewer recordings.')
        // Said before anything of them is loaded.
        ->and($tooMany->first()->relationLoaded('primaryRecording'))->toBeFalse();
});

it('copies for AI what the apps copy: the note once, each recording\'s header and its lines, and leaves out what has no transcript', function () {
    ['text' => $text, 'copied' => $copied, 'skipped' => $skipped] = V2CopyForAi::forMany([$this->done->fresh(), $this->waiting->fresh()]);

    expect($copied)->toBe(1)->and($skipped)->toBe(1)
        ->and($text)->toBe(implode("\n", [
            'Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio.',
            '',
            // The desktop's aiHeader for the same recording (desktop/src/v2/library/selection.test.tsx).
            '# Kick-off',
            '16 November 2026, 4:30 pm · Zoom · 1:00',
            'People: Me, Habib',
            'Language: UR',
            '',
            '[0:00] Me: Assalam o alaikum.',
            '[1:05] Habib: The [?proforma] is ready.',
        ]));
});

it('puts the text on the clipboard with the apps\' notice, or says there is nothing to copy', function () {
    $page = Livewire::test(ListCaptureSessions::class)->callTableBulkAction('copyForAiInMenu', [$this->done->id, $this->waiting->id]);
    $js = collect($page->effects['xjs'] ?? [])->map(fn ($e): string => is_array($e) ? (string) ($e['expression'] ?? '') : (string) $e)->implode("\n");
    expect($js)->toContain('window.elV2Selection.copy(')->toContain('# Kick-off')->toContain('Copied 1 for AI · 1 without a transcript left out')
        ->toContain("'Copy failed'")->not->toContain('Could not access the clipboard');

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('copyForAiInMenu', [$this->waiting->id]);
    expect(selectionMoreNotices())->toContain('This recording has no transcript to copy yet');
});

it('writes the lines exactly as the recording player does (the Node test against the player)', function () {
    $lines = V2CopyForAi::lines($this->done->fresh(), 0.5);
    $fixture = storage_path('framework/testing/v2-copy-ai.json');
    @mkdir(dirname($fixture), 0755, true);
    file_put_contents($fixture, (string) json_encode([
        'lines' => $lines,
        'brackets' => V2CopyForAi::body($lines, true, 'brackets'),
        'suffix' => V2CopyForAi::body($lines, false, 'suffix'),
        'note' => V2CopyForAi::note(['style' => 'brackets'], 'brackets'),
    ], JSON_UNESCAPED_UNICODE));

    $result = Process::path(base_path())->env(['V2_COPY_AI_FIXTURE' => $fixture])->timeout(60)->run(['node', '--test', 'tests/js/v2-copy-ai.test.mjs']);
    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

/** A teammate in the owner's workspace with the given role, working in it, with a recording of theirs whose audio is here. */
function selectionMate(object $test, WorkspaceRole $role): array
{
    $workspaceId = (int) $test->owner->current_workspace_id;
    $mate = Fx::user(['name' => 'Sana Malik']);
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($workspaceId), $mate, $role);
    $mate->forceFill(['current_workspace_id' => $workspaceId])->save();
    $theirs = Fx::session($mate, null, ['title' => 'Sana call', 'workspace_id' => $workspaceId, 'duration_ms' => 60_000]);
    Fx::readyRecording($theirs, 'audio of Sana call');

    return [$mate->fresh(), $theirs];
}

it('gives a read-only console no Transcribe, Mark important or Unmark important, and nothing starts or changes when called directly', function () {
    [$member, $theirs] = selectionMate($this, WorkspaceRole::Member);
    Queue::fake();
    $this->actingAs($member, 'web');

    $page = Livewire::test(ListCaptureSessions::class);
    foreach (['transcribeInMenu', 'importantInMenu', 'unimportantInMenu'] as $action) {
        $page->assertTableBulkActionDoesNotExist($action);
        try {
            Livewire::test(ListCaptureSessions::class)->callTableBulkAction($action, [$theirs->id]);
        } catch (Throwable) {
            // Refused before it runs: what matters is that nothing changed.
        }
    }
    expect($theirs->fresh()->finalTranscript)->toBeNull()->and($theirs->fresh()->important)->toBeFalse();
    // And the actions' own checks refuse a read-only console even if they were reached.
    expect(CaptureSessionsTable::changeable(CaptureSession::query()->whereKey($theirs->id)->get()))->toHaveCount(0)
        ->and(CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($theirs->id)->get())['ready'])->toBe([]);
});

it('starts and marks only the person\'s own recordings in a mixed selection, and copies only what they may open', function () {
    [, $theirs] = selectionMate($this, WorkspaceRole::Admin);
    Plan::personal()->update(['monthly_minutes' => null]);
    Queue::fake();
    $mixed = CaptureSession::query()->whereIn('id', [$this->waiting->id, $theirs->id])->get();

    expect(CaptureSessionsTable::changeable($mixed)->modelKeys())->toBe([$this->waiting->id])
        ->and(array_map(fn (CaptureSession $r): string => (string) $r->id, CaptureSessionsTable::transcribePlan($mixed)['ready']))->toBe([(string) $this->waiting->id])
        ->and(CaptureSessionsTable::viewable($mixed)->modelKeys())->toBe([$this->waiting->id]);

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$this->waiting->id, $theirs->id]);
    expect(selectionRunQueuedStarts())->toBe(1)->and($this->waiting->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Pending)
        ->and($theirs->fresh()->finalTranscript)->toBeNull();

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('importantInMenu', [$this->waiting->id, $theirs->id]);
    expect($this->waiting->fresh()->important)->toBeTrue()->and($theirs->fresh()->important)->toBeFalse();
});

it('starts recordings on Free with the person\'s own Groq key: they are not charged to the month, so its 0 minutes don\'t stop them', function () {
    selectionPlan($this, 'free', 0);
    $this->owner->forceFill(['transcription_key' => 'gsk_'.str_repeat('a', 40).'WXYZ', 'transcription_key_provider' => 'groq'])->save();
    Queue::fake();
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($this->waiting->id)->get());
    // Nothing of it counts against the month (the job uses the person's own key); the window says the length only.
    expect($plan['minutes'])->toBe(0)->and($plan['left'])->toBe(0)->and($plan['can'])->toBeTrue()
        ->and($plan['words'])->toBe('About 3 minutes.');

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$this->waiting->id]);
    expect(selectionMoreNotices())->toContain('Transcribing 1 recording');
    expect(selectionRunQueuedStarts())->toBe(1)
        ->and($this->waiting->fresh()->finalTranscript?->meta['own_key'] ?? null)->toBeTrue();
});

it('counts the recording\'s channels as the usage ledger bills them (length × channels)', function () {
    selectionPlan($this, 'pro', 120);
    $this->waiting->primaryRecording->forceFill(['channels' => 1])->save();
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($this->waiting->id)->get());
    // 2:30 on one channel is 3 billed minutes; on two (above) it is 5.
    expect($plan['minutes'])->toBe(3)->and($plan['words'])->toBe('About 3 minutes of the 120 minutes left this month.');
});

it('starts each one on its own and says honestly how many could not start', function () {
    selectionPlan($this, 'pro', null);
    $second = Fx::session($this->owner, $this->device, ['title' => 'Second call', 'duration_ms' => 30_000, 'status' => SessionStatus::Completed]);
    Fx::readyRecording($second, 'audio of the second call');
    Queue::fake();
    // The queue refuses the second start (it is down for a moment): that one alone fails, and the first still starts.
    $this->app->extend(Dispatcher::class, fn ($bus, $app) => new class($app, fn ($connection = null) => $app['queue']->connection($connection)) extends Dispatcher
    {
        private int $closures = 0;

        public function dispatchToQueue($command)
        {
            if ($command instanceof CallQueuedClosure && ++$this->closures === 2) {
                throw new RuntimeException('The queue is not reachable');
            }

            return parent::dispatchToQueue($command);
        }
    });

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$this->waiting->id, $second->id]);
    expect(selectionMoreNotices())->toContain('Transcribing 1 recording · 1 could not start');
    expect(Queue::pushed(CallQueuedClosure::class))->toHaveCount(1);
});

it('says "the first 200 of N" when Copy for AI gets more than it takes', function () {
    $many = collect(range(1, 201))->map(fn (int $i): string => (string) Fx::session($this->owner, $this->device, ['title' => "Copy {$i}"])->id)->all();
    $page = Livewire::test(ListCaptureSessions::class)->callTableBulkAction('copyForAiInMenu', [$this->done->id, ...$many]);
    $js = collect($page->effects['xjs'] ?? [])->map(fn ($e): string => is_array($e) ? (string) ($e['expression'] ?? '') : (string) $e)->implode("\n");
    expect($js)->toContain('the first 200 of 202');
});

it('copies 200 recordings for AI in a few queries, not a few for each recording', function () {
    $ids = collect(range(1, 40))->map(function (int $i): string {
        $s = Fx::session($this->owner, $this->device, ['title' => "Q {$i}"]);
        $s->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed,
            'text' => 'Line', 'segments' => [['speaker' => 'A', 'start_ms' => 0, 'end_ms' => 500, 'text' => 'Line', 'words' => []]]]);

        return (string) $s->id;
    });
    $sessions = CaptureSession::query()->whereIn('id', $ids)->get();
    DB::enableQueryLog();
    $result = V2CopyForAi::forMany($sessions);
    $queries = count(DB::getQueryLog());
    expect($result['copied'])->toBe(40)->and($queries)->toBeLessThan(10);
});

it('shows the text with its own Copy button when the browser refuses a copy made after the round trip (Safari)', function () {
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain('this.offer(text, done, failed);')->toContain('box.showModal();')
        ->toContain('Your browser did not let the page copy by itself. Press Copy.')
        ->toContain("box.querySelector('[data-copy-now]').onclick = async () => {");
    // The Copy button's own click writes the clipboard (a fresh user gesture), and one failure wording is used.
    expect(substr_count($html, 'Could not access the clipboard'))->toBe(0);
});

/** A recording of [$owner] kept as audio only (the after-call card's Save audio), its audio here, two channels. */
function selectionAudioOnly(object $test, string $title, int $ms, ?object $owner = null, array $attributes = []): CaptureSession
{
    $session = Fx::session($owner ?? $test->owner, $owner === null ? $test->device : null, ['title' => $title, 'duration_ms' => $ms,
        'status' => SessionStatus::AudioOnly, 'transcribe' => false, ...$attributes]);
    Fx::readyRecording($session, 'audio of '.$title);

    return $session->fresh();
}

/** A recording whose transcript failed (the engine could not be reached), its audio here. */
function selectionFailedTranscript(object $test, string $title, int $ms): CaptureSession
{
    $session = Fx::session($test->owner, $test->device, ['title' => $title, 'duration_ms' => $ms, 'status' => SessionStatus::Failed]);
    Fx::readyRecording($session, 'audio of '.$title);
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed,
        'error_code' => TranscriptErrorCode::ProviderUnreachable->value]);

    return $session->fresh();
}

/** @param  list<CaptureSession>  $sessions */
function selectionIds(array $sessions): array
{
    return array_map(fn (CaptureSession $r): string => (string) $r->id, $sessions);
}

/** The one notice sent so far. */
function selectionMoreNotice(): array
{
    $notices = json_decode(selectionMoreNotices(), true);
    expect($notices)->toHaveCount(1);

    return array_values($notices)[0];
}

it('starts audio-only recordings through TranscribeLater (2.0.0-j): its minutes in the window, started at once', function () {
    selectionPlan($this, 'pro', 120);
    Queue::fake();
    $first = selectionAudioOnly($this, 'Saved call', 150_000);
    $second = selectionAudioOnly($this, 'Second saved call', 30_000);

    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereIn('id', [$first->id, $second->id, $this->done->id])->get());
    // TranscribeLater's plan minutes: each length rounded up, times the billed channels (2:30 and 0:30 in stereo: 6 + 2).
    expect($plan['minutes'])->toBe(8)->and($plan['left'])->toBe(120)->and($plan['can'])->toBeTrue()
        ->and($plan['words'])->toBe('About 8 minutes of the 120 minutes left this month.')
        ->and(selectionIds($plan['later']))->toEqualCanonicalizing([(string) $first->id, (string) $second->id])->and($plan['restart'])->toBe([]);

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$first->id, $second->id, $this->done->id]);
    expect(selectionMoreNotices())->toContain('Transcribing 2 recordings · 1 skipped (already transcribed or no audio)');
    foreach ([$first, $second] as $session) {
        $session = $session->fresh();
        expect($session->status)->toBe(SessionStatus::Processing)->and($session->transcribe)->toBeTrue()
            ->and($session->finalTranscript?->status)->toBe(TranscriptStatus::Pending);
    }
    // Not the console's own queued restart: TranscribeLater started them (their minutes now count as in flight).
    expect(Queue::pushed(CallQueuedClosure::class))->toHaveCount(0)
        ->and(app(TranscribeLater::class)->minutesLeft((int) $this->owner->current_workspace_id, (int) $this->owner->id, fresh: true))->toBe(112);
});

it('starts a mixed selection: the audio-only one through TranscribeLater, the failed transcript again as before, one notice for both', function () {
    selectionPlan($this, 'pro', 120);
    Queue::fake();
    $saved = selectionAudioOnly($this, 'Saved call', 60_000);
    $failed = selectionFailedTranscript($this, 'Failed call', 60_000);

    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereIn('id', [$saved->id, $failed->id, $this->waiting->id])->get());
    // One sentence over both: 1:00 stereo through TranscribeLater (2), 1:00 stereo restarted (2) and 2:30 stereo (5).
    expect(selectionIds($plan['later']))->toBe([(string) $saved->id])
        ->and(selectionIds($plan['restart']))->toEqualCanonicalizing([(string) $failed->id, (string) $this->waiting->id])
        ->and($plan['minutes'])->toBe(9)->and($plan['words'])->toBe('About 9 minutes of the 120 minutes left this month.');

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$saved->id, $failed->id, $this->waiting->id]);
    expect(selectionMoreNotices())->toContain('Transcribing 3 recordings')->not->toContain('skipped')->not->toContain('could not start');
    expect($saved->fresh()->status)->toBe(SessionStatus::Processing)->and($saved->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Pending);
    // The failed one (and the one kept without a transcript) start again on the queue, as the recording page's Transcribe again.
    expect($failed->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Failed)->and(selectionRunQueuedStarts())->toBe(2)
        ->and($failed->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Pending)
        ->and($this->waiting->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Pending);
});

it('starts nothing on Free without the person\'s own key, and says where to add it, never the server\'s words', function () {
    selectionPlan($this, 'free', 0);
    Queue::fake();
    $saved = selectionAudioOnly($this, 'Saved call', 60_000);
    $failed = selectionFailedTranscript($this, 'Failed call', 60_000);

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$saved->id, $failed->id]);
    $notice = selectionMoreNotice();
    expect($notice['title'])->toBe('This plan transcribes with your own Groq key. Add it in Settings › Transcription key.')
        ->and($notice['status'])->toBe('danger')->and($notice['url'])->toBe(EditProfile::settingsUrl('transcription-key'))
        ->and($notice['title'])->not->toContain(TranscriptErrorCode::plainWords(TranscriptErrorCode::NeedsTranscriptionKey));
    // All or none: the failed one does not start either.
    expect($saved->fresh()->status)->toBe(SessionStatus::AudioOnly)->and($saved->fresh()->finalTranscript)->toBeNull()
        ->and(Queue::pushed(CallQueuedClosure::class))->toHaveCount(0)->and($failed->fresh()->finalTranscript?->status)->toBe(TranscriptStatus::Failed);

    // In the console's language.
    app()->setLocale('ur');
    expect(CaptureSessionsTable::transcribeRefused(new ApiError(403, 'needs_transcription_key', 'x'), 1)->getTitle())
        ->toBe('یہ پلان آپ کی اپنی Groq کلید سے ٹرانسکرائب کرتا ہے۔ اسے ترتیبات › ٹرانسکرپشن کی کلید میں شامل کریں۔');
});

it('starts nothing over the month\'s minutes and says so as the desktop does', function () {
    selectionPlan($this, 'pro', 5);
    Queue::fake();
    // 2:30 in stereo is 6 of TranscribeLater's minutes, one more than is left.
    $saved = selectionAudioOnly($this, 'Saved call', 150_000);
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($saved->id)->get());
    expect($plan['can'])->toBeFalse()
        ->and($plan['words'])->toBe('About 6 minutes of the 5 minutes left this month. That is more than is left, so nothing will start. Pick fewer recordings.');

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$saved->id]);
    $notice = selectionMoreNotice();
    expect($notice['title'])->toBe('This needs 6 minutes, and 5 minutes are left this month.')->and($notice['status'])->toBe('danger');
    expect($saved->fresh()->status)->toBe(SessionStatus::AudioOnly)->and($saved->fresh()->finalTranscript)->toBeNull();

    // When the server refuses it (minutes spent elsewhere a moment before): the same sentence, never the server's own.
    $refused = fn (string $code, array $extra = []) => CaptureSessionsTable::transcribeRefused(new ApiError(403, $code, PlanLimits::MINUTES_MESSAGE, $extra), 3);
    expect($refused('budget_exceeded', ['limit' => 'monthly_minutes', 'minutes_needed' => 6, 'minutes_left' => 0])->getTitle())
        ->toBe('This needs 6 minutes and no minutes are left this month.')
        ->and($refused('budget_exceeded', ['minutes_needed' => 1, 'minutes_left' => 1])->getTitle())->toBe('This needs 1 minute, and 1 minute is left this month.')
        ->and($refused('rate_limited')->getTitle())->toBe('Another transcription is being started. Try again in a moment.')
        ->and($refused('server_busy')->getTitle())->toBe('Another transcription is being started. Try again in a moment.')
        ->and($refused('something_new')->getTitle())->toBe('Nothing to transcribe · 3 could not start');
});

it('starts nothing while another start of the workspace holds it, and asks to try again', function () {
    selectionPlan($this, 'pro', 120);
    Queue::fake();
    $saved = selectionAudioOnly($this, 'Saved call', 60_000);
    $failed = selectionFailedTranscript($this, 'Failed call', 60_000);
    $wait = TranscribeLater::$lockWaitSeconds;
    TranscribeLater::$lockWaitSeconds = 0;
    $lock = Cache::lock('transcribe-later:'.$this->owner->current_workspace_id, 30);
    $lock->get();
    try {
        Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$saved->id, $failed->id]);
    } finally {
        $lock->release();
        TranscribeLater::$lockWaitSeconds = $wait;
    }
    $notice = selectionMoreNotice();
    expect($notice['title'])->toBe('Another transcription is being started. Try again in a moment.')->and($notice['status'])->toBe('danger');
    expect($saved->fresh()->status)->toBe(SessionStatus::AudioOnly)->and(Queue::pushed(CallQueuedClosure::class))->toHaveCount(0);
});

it('never starts an audio-only recording for a read-only console or a viewer', function () {
    // A member (the small console, read-only): no Transcribe, and nothing starts when it is called directly.
    [$member, $theirs] = selectionMate($this, WorkspaceRole::Member);
    $theirs->forceFill(['status' => SessionStatus::AudioOnly, 'transcribe' => false])->save();
    Queue::fake();
    $this->actingAs($member, 'web');
    Livewire::test(ListCaptureSessions::class)->assertTableBulkActionDoesNotExist('transcribeInMenu');
    try {
        Livewire::test(ListCaptureSessions::class)->callTableBulkAction('transcribeInMenu', [$theirs->id]);
    } catch (Throwable) {
        // Refused before it runs: what matters is that nothing started.
    }
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($theirs->id)->get());
    expect($theirs->fresh()->status)->toBe(SessionStatus::AudioOnly)->and($theirs->fresh()->finalTranscript)->toBeNull()
        ->and($plan['ready'])->toBe([])->and($plan['later'])->toBe([]);

    // The server's operator has the whole console, but as a viewer here their own audio-only recording from before does
    // not start: TranscribeLater refuses a viewer, and Transcribe… leaves it out.
    $workspaceId = (int) $this->owner->current_workspace_id;
    $operator = Fx::superAdmin(['name' => 'Ops']);
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($workspaceId), $operator, WorkspaceRole::Viewer);
    $old = selectionAudioOnly($this, 'Before the change', 60_000, $operator, ['workspace_id' => $workspaceId]);
    $operator->forceFill(['current_workspace_id' => $workspaceId])->save();
    $this->actingAs($operator->fresh(), 'web');
    expect(CurrentWorkspace::id())->toBe($workspaceId);
    $plan = CaptureSessionsTable::transcribePlan(CaptureSession::query()->whereKey($old->id)->get());
    expect($plan['ready'])->toBe([])->and($plan['can'])->toBeFalse()
        ->and($plan['words'])->toBe('Nothing here can be transcribed: each one is already transcribed or has no audio.');
    expect($old->fresh()->status)->toBe(SessionStatus::AudioOnly)->and($old->fresh()->finalTranscript)->toBeNull();
});
