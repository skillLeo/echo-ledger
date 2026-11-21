<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\SettingsContext;
use App\Filament\Widgets\V2NeedsActionWidget;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The v2.0 console's needs-action lines can be closed with × (U13, the desktop's rule): the Library's "N calls waiting
 * for a decision" and Home's "Needs action" stay hidden until a count rises or a new kind appears; a fall keeps them
 * hidden at the lower count. Kept per person in console_preferences, so a read-only console can close its own line
 * and another person still sees theirs.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->waitingCall = fn ($user, ?int $workspace = null) => Fx::session($user, null, array_filter([
        'title' => 'Call to keep or discard', 'status' => SessionStatus::Recording, 'started_at' => now()->subHour(), 'workspace_id' => $workspace,
    ]));
    $this->first = ($this->waitingCall)($this->owner);
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

/** The Library as [$user] gets it on a fresh load. */
function dismissLibraryHtml(object $test, $user): string
{
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($user->fresh(), 'web')->get('/app/sessions')->assertOk()->getContent();
}

it('closes the Library\'s waiting line with ×, and it stays closed on reload while the count is the same', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->html())->toContain('data-waiting="1"')->toContain('wire:click="dismissV2Waiting(1)"')
        ->toMatch('~<button type="button" class="v2-icon-btn" wire:click="dismissV2Waiting\(1\)" aria-label="Dismiss" title="Dismiss" data-dismiss="library">~');

    $page->call('dismissV2Waiting', 1);
    expect($page->html())->not->toContain('data-waiting=')
        ->and($this->owner->fresh()->console_preferences['dismissed']['library'])->toBe(['waiting' => 1]);
    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=')->toContain('v2-chips');
});

it('shows the line again when more calls wait than it said', function () {
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting', 1);
    ($this->waitingCall)($this->owner);

    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="2"')->toContain('2 calls waiting for a decision');
    expect($this->owner->fresh()->console_preferences['dismissed'] ?? null)->toBeNull();
});

it('keeps the line closed when the count falls, at the lower count, so a later rise shows it', function () {
    ($this->waitingCall)($this->owner);
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting', 2);
    $this->first->forceFill(['status' => SessionStatus::Completed])->save();

    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=')
        ->and($this->owner->fresh()->console_preferences['dismissed']['library'])->toBe(['waiting' => 1]);
    ($this->waitingCall)($this->owner);
    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="2"');
});

it('closes only what the line said when it was drawn: a call that arrived since still shows', function () {
    ($this->waitingCall)($this->owner);
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting', 1);

    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="2"');
});

it('names the × Dismiss in English and in Urdu', function () {
    expect(dismissLibraryHtml($this, $this->owner))->toContain('aria-label="Dismiss" title="Dismiss" data-dismiss="library"');
    $this->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    expect(dismissLibraryHtml($this, $this->owner))->toContain('aria-label="ہٹا دیں" title="ہٹا دیں" data-dismiss="library"');
});

it('lets a read-only console close its own line, and another person still sees theirs', function () {
    $workspace = Workspace::query()->findOrFail($this->owner->current_workspace_id);
    $member = Fx::user(['name' => 'Sana Malik']);
    app(Workspaces::class)->addMember($workspace, $member, WorkspaceRole::Member);
    $member->forceFill(['current_workspace_id' => $workspace->id])->save();
    ($this->waitingCall)($member, $workspace->id);
    $this->actingAs($member->fresh(), 'web');
    expect(ConsoleAccess::readOnly())->toBeTrue();

    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->html())->toContain('data-waiting="1"')->toContain('data-dismiss="library"');
    $page->call('dismissV2Waiting', 1);
    expect($page->html())->not->toContain('data-waiting=');
    expect(dismissLibraryHtml($this, $member))->not->toContain('data-waiting=');
    // The owner has not closed theirs.
    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="1"')
        ->and($this->owner->fresh()->console_preferences['dismissed'] ?? null)->toBeNull();
});

it('closes Home\'s Needs action line with × until a count rises or a new kind appears', function () {
    $home = fn (): string => (function () {
        app('auth')->forgetGuards();
        SettingsContext::forget();

        return (string) $this->actingAs($this->owner->fresh(), 'web')->get('/app')->assertOk()->getContent();
    })->call($this);
    expect($home())->toContain('data-needs="waiting"')->toContain('aria-label="Dismiss" title="Dismiss" data-dismiss="home"');

    Livewire::test(V2NeedsActionWidget::class)->call('dismiss', ['waiting' => 1])->assertDontSeeHtml('data-needs-action');
    expect($home())->not->toContain('data-needs-action')
        ->and($this->owner->fresh()->console_preferences['dismissed']['home'])->toBe(['waiting' => 1]);

    // A new kind (a failed upload) shows it again.
    Fx::session($this->owner, null, ['title' => 'Transcript that failed', 'status' => SessionStatus::Failed]);
    expect($home())->toContain('data-needs="failed"')->toContain('data-needs="waiting"');

    // Closed again; one more waiting call (a rise) shows it again. The Library's own line is apart from Home's.
    $this->actingAs($this->owner->fresh(), 'web');
    request()->attributes->remove('v2.needs-action.'.$this->owner->id.'.'.$this->owner->current_workspace_id);
    Livewire::test(V2NeedsActionWidget::class)->call('dismiss', ['waiting' => 1, 'failed' => 1]);
    expect($home())->not->toContain('data-needs-action')
        ->and(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="1"');
    ($this->waitingCall)($this->owner);
    expect($home())->toContain('data-needs-action');
});
