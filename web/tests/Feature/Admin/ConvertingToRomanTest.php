<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Support\RecordingProgress;
use App\Filament\Support\V2CopyForAi;
use App\Jobs\MakeTranscriptViewsJob;
use App\Models\CaptureSession;
use App\Models\ShareLink;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\InterfaceFlags;
use App\Services\SettingsRepository;
use App\Services\Views\TranscriptViews;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * U19 in the console, classic and new design: "Converting to Roman Urdu… 60%" with a small spinner on the recording page
 * and the Library rows from the moment the transcript is there, "Couldn't convert to Roman Urdu · Try again" when it
 * could not be made, and every copy, the share link and the exports in the view shown (Roman Urdu once it is made).
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->admin = $this->signInAsAdmin();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
});

/** An Urdu voice note just transcribed, with [$views] stored as its views (none: nothing has started them). */
function u19Recording(object $test, ?array $views = null, array $session = []): CaptureSession
{
    $segments = [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'میٹنگ کا ٹائم', 'words' => []],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 65_000, 'end_ms' => 67_000, 'text' => 'ٹیکس فارم ٹھیک کرنا ہے', 'words' => []],
    ];
    $model = CaptureSession::factory()->create(['user_id' => $test->admin->id, 'status' => SessionStatus::Completed, 'language' => 'ur',
        'source_app' => 'voice_note', 'duration_ms' => 70_000, 'title' => 'Meeting ka time', 'title_auto' => true, ...$session]);
    $final = Transcript::query()->create(['capture_session_id' => $model->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq', 'language' => 'ur',
        'status' => TranscriptStatus::Completed, 'completed_at' => now(), 'text' => 'x', 'segments' => $segments]);
    if ($views !== null) {
        $final->forceFill(['meta' => ['views' => ['fingerprint' => TranscriptViews::fingerprint($final), ...$views]]])->save();
    }

    return $model->fresh();
}

/** The Roman Urdu view made. */
function u19Ready(): array
{
    return ['status' => 'ready', 'model' => 'claude-opus-5-5', 'made_at' => now()->toAtomString(), 'kept' => [],
        'roman' => ['0' => 'meeting ka time', '1' => 'tax form theek karna hai'], 'urdu' => ['0' => 'میٹنگ کا ٹائم', '1' => 'ٹیکس فارم ٹھیک کرنا ہے'],
        'english' => ['0' => 'meeting time', '1' => 'the tax form needs fixing']];
}

function u19V2(): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
}

it('shows "Converting to Roman Urdu…" with a spinner on the recording page from the moment the transcript is there', function () {
    $session = u19Recording($this);

    expect(RecordingProgress::pageBar($session))->toBe(['label' => 'Converting to Roman Urdu…', 'percent' => 0, 'failed' => false]);
    $html = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->html();
    expect($html)->toContain('Converting to Roman Urdu…')->toContain('data-converting')->toContain('wire:poll.5s="refreshWork"');

    // The job counted the lines: the share shows.
    $moving = u19Recording($this, ['status' => 'making', 'started_at' => now()->toAtomString(), 'lines_done' => 6, 'lines_total' => 10]);
    expect($this->panelComponent(ViewCaptureSession::class, ['record' => $moving->id])->html())->toContain('Converting to Roman Urdu… 60%');
});

it('says "Couldn\'t convert to Roman Urdu" with Try again when it failed, and Try again starts it again', function () {
    Queue::fake();
    $session = u19Recording($this, ['status' => 'failed', 'error' => 'The AI was busy.']);

    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    expect($page->html())->toContain('Couldn&#039;t convert to Roman Urdu')->toContain('data-retry-views')->not->toContain('data-converting');

    $page->call('retryViews')->assertDispatched('el-transcript-updated');
    Queue::assertPushed(MakeTranscriptViewsJob::class, 1);
    expect($page->html())->toContain('Converting to Roman Urdu…')->not->toContain('data-retry-views');
});

it('turns the spinner into "Couldn\'t convert" when the conversion goes quiet for 20 minutes, never spinning for ever', function () {
    $session = u19Recording($this, ['status' => 'making', 'started_at' => now()->toAtomString()]);
    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    expect($page->html())->toContain('Converting to Roman Urdu…');

    $this->travel(21)->minutes();
    $page->call('refreshWork');
    expect($page->html())->toContain('Couldn&#039;t convert to Roman Urdu')->toContain('data-retry-views');
});

it('shows the spinner and the share in the classic Library row, and "Couldn\'t convert" there when it failed', function () {
    u19Recording($this, ['status' => 'making', 'started_at' => now()->toAtomString(), 'lines_done' => 3, 'lines_total' => 10]);
    u19Recording($this, ['status' => 'failed', 'error' => 'x'], ['title' => 'Second']);

    $html = $this->panelComponent(ListCaptureSessions::class)->html();
    // The third dot active: the theme draws it as the small spinner.
    expect($html)->toContain('Converting to Roman Urdu… 30%')->toMatch('/data-state="done"><\/span>\s*<span class="el-steps-dot" data-state="done"><\/span>\s*<span class="el-steps-dot" data-state="active">/')
        ->toContain('data-convert-failed')
        ->toContain('Couldn&#039;t convert to Roman Urdu')->toContain('wire:poll.5s');
});

it('shows "Converting to Roman Urdu…" with a spinner in the new Library\'s rows, also before the job has started', function () {
    u19V2();
    u19Recording($this);
    u19Recording($this, ['status' => 'making', 'started_at' => now()->toAtomString(), 'lines_done' => 6, 'lines_total' => 10], ['title' => 'Second']);
    u19Recording($this, u19Ready(), ['title' => 'Third']);

    $html = $this->panelComponent(ListCaptureSessions::class)->html();
    expect($html)->toContain('v2-spinner')->toContain('Converting to Roman Urdu…<')->toContain('Converting to Roman Urdu… 60%')
        ->and(substr_count($html, 'data-converting'))->toBe(2);
});

it('copies for AI from the Library in Roman Urdu once it is made, and says so in the header', function () {
    $ready = u19Recording($this, u19Ready());
    $waiting = u19Recording($this, null, ['title' => 'Not yet']);

    ['text' => $text] = V2CopyForAi::forMany([$ready->fresh(), $waiting->fresh()]);
    [$first, $second] = explode("\n\n---\n\n", $text);
    expect($first)->toContain("Language: Roman Urdu\n")->toContain('[0:00] Me: meeting ka time')->toContain('[1:05] Client: tax form theek karna hai')
        ->not->toContain('میٹنگ')
        ->and($second)->toContain('Language: UR')->toContain('میٹنگ کا ٹائم');
    expect(V2CopyForAi::header($ready, ['Me'], 'roman'))->toEndWith('Language: Roman Urdu')
        ->and(V2CopyForAi::shownView($ready))->toBe('roman')->and(V2CopyForAi::shownView($waiting))->toBeNull();
});

it('starts the share link, the download and the Library\'s export in the view shown', function () {
    $session = u19Recording($this, u19Ready());
    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    expect($page->get('readingView'))->toBe('roman');

    $page->callAction('share', data: ['audience' => 'public', 'expires' => '86400'])->assertHasNoActionErrors();
    expect(ShareLink::query()->latest('id')->first()?->view)->toBe('roman');

    expect(CaptureSessionsTable::shownViewDefault())->toBe('roman');
    app(SettingsRepository::class)->set('views.default_ur', 'original');
    expect(CaptureSessionsTable::shownViewDefault())->toBeNull();
});
