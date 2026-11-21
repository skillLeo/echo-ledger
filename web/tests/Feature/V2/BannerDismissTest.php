<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\V2DismissedBanners;
use App\Filament\Widgets\V2NeedsActionWidget;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The v2.0 console's needs-action lines can be closed with × (U13, the desktop's rule): the Library's "N calls waiting
 * for a decision" and Home's "Needs action" stay hidden until a count rises above what it was when closed, or a new
 * kind appears. The counts are the server's when × is pressed, never the page's, and drawing a page writes nothing.
 * Kept per person in console_preferences, so a read-only console can close its own line and another person still
 * sees theirs.
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
    expect($page->html())->toContain('data-waiting="1"')
        ->toContain('data-dismiss="library"');

    $page->call('dismissV2Waiting');
    expect($page->html())->not->toContain('data-waiting=')
        ->and($this->owner->fresh()->console_preferences['dismissed'][(string) $this->owner->current_workspace_id]['library'])->toBe(['waiting' => 1]);
    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=')->toContain('v2-chips');
});

it('shows the line again when more calls wait than it said', function () {
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting');
    ($this->waitingCall)($this->owner);

    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="2"')->toContain('2 calls waiting for a decision');
    expect($this->owner->fresh()->console_preferences['dismissed'] ?? null)->toBeNull();
});

it('keeps the line closed when the count falls, and shows it only when it rises above the closed count', function () {
    ($this->waitingCall)($this->owner);
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting');
    $this->first->forceFill(['status' => SessionStatus::Completed])->save();

    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=')
        ->and($this->owner->fresh()->console_preferences['dismissed'][(string) $this->owner->current_workspace_id]['library'])->toBe(['waiting' => 2]);
    ($this->waitingCall)($this->owner);
    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=');
    ($this->waitingCall)($this->owner);
    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="3"');
});

it('writes nothing when a page is drawn: only closing and a rise are saved', function () {
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting');
    $writes = 0;
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^update\s+"?users"?/i', $query->sql) === 1) {
            $writes++;
        }
    });
    foreach (range(1, 3) as $_) {
        expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=');
    }
    $this->first->forceFill(['status' => SessionStatus::Completed])->save(); // a fall
    dismissLibraryHtml($this, $this->owner);
    expect($writes)->toBe(0);

    ($this->waitingCall)($this->owner);
    ($this->waitingCall)($this->owner);
    dismissLibraryHtml($this, $this->owner);
    dismissLibraryHtml($this, $this->owner);
    expect($writes)->toBe(1);
});

it('closes at the server\'s counts when × is pressed, whatever the page sends', function () {
    ($this->waitingCall)($this->owner);
    // The page could send anything: a count far above, or below, the real one.
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting', 99);
    expect($this->owner->fresh()->console_preferences['dismissed'][(string) $this->owner->current_workspace_id]['library'])->toBe(['waiting' => 2]);
    ($this->waitingCall)($this->owner);
    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="3"');

    $this->actingAs($this->owner->fresh(), 'web');
    request()->attributes->remove('v2.needs-action.'.$this->owner->id.'.'.$this->owner->current_workspace_id);
    Livewire::test(V2NeedsActionWidget::class)->call('dismiss', ['waiting' => 500, 'failed' => 500]);
    expect($this->owner->fresh()->console_preferences['dismissed'][(string) $this->owner->current_workspace_id]['home'])->toBe(['waiting' => 3]);
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
    $page->call('dismissV2Waiting');
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
    expect($home())->toContain('data-needs="waiting"')->toContain('data-dismiss="home"');

    Livewire::test(V2NeedsActionWidget::class)->call('dismiss')->assertDontSeeHtml('data-needs-action');
    expect($home())->not->toContain('data-needs-action')
        ->and($this->owner->fresh()->console_preferences['dismissed'][(string) $this->owner->current_workspace_id]['home'])->toBe(['waiting' => 1]);

    // A new kind (a failed upload) shows it again.
    Fx::session($this->owner, null, ['title' => 'Transcript that failed', 'status' => SessionStatus::Failed]);
    expect($home())->toContain('data-needs="failed"')->toContain('data-needs="waiting"');

    // Closed again; one more waiting call (a rise) shows it again. The Library's own line is apart from Home's.
    $this->actingAs($this->owner->fresh(), 'web');
    request()->attributes->remove('v2.needs-action.'.$this->owner->id.'.'.$this->owner->current_workspace_id);
    Livewire::test(V2NeedsActionWidget::class)->call('dismiss');
    expect($home())->not->toContain('data-needs-action')
        ->and(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="1"');
    ($this->waitingCall)($this->owner);
    expect($home())->toContain('data-needs-action');
});

it('keeps a closed line per workspace: another workspace of the same person still shows its own', function () {
    $team = Workspace::query()->create(['name' => 'Second team', 'plan_id' => Workspace::query()->findOrFail($this->owner->current_workspace_id)->plan_id]);
    app(Workspaces::class)->addMember($team, $this->owner, WorkspaceRole::Owner);
    $first = (int) $this->owner->current_workspace_id;
    ($this->waitingCall)($this->owner, $team->id);

    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting');
    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=');

    app(Workspaces::class)->switchTo($this->owner, $team);
    expect(dismissLibraryHtml($this, $this->owner))->toContain('data-waiting="1"')
        ->and($this->owner->fresh()->console_preferences['dismissed'])->toBe([(string) $first => ['library' => ['waiting' => 1]]]);

    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(ListCaptureSessions::class)->call('dismissV2Waiting');
    expect($this->owner->fresh()->console_preferences['dismissed'])->toHaveKeys([(string) $first, (string) $team->id]);
    app(Workspaces::class)->switchTo($this->owner->fresh(), Workspace::query()->findOrFail($first));
    expect(dismissLibraryHtml($this, $this->owner))->not->toContain('data-waiting=');
});

it('moves focus after × to a control that stays: the Library\'s Views button, Home\'s page heading', function () {
    $library = dismissLibraryHtml($this, $this->owner);
    expect(html_entity_decode($library))->toContain('x-on:click="$wire.dismissV2Waiting().then(() => document.querySelector(\'[data-menu=views]\')?.focus())"')
        ->toContain('data-menu="views"')->not->toContain('wire:click="dismissV2Waiting"');

    app('auth')->forgetGuards();
    SettingsContext::forget();
    $home = html_entity_decode((string) $this->actingAs($this->owner->fresh(), 'web')->get('/app')->assertOk()->getContent());
    expect($home)->toContain("\$wire.dismiss().then(() => { const heading = document.querySelector('.fi-header-heading'); if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); } })")
        ->toContain('class="fi-header-heading"');
});

it('writes only its own part of the preferences, read again under a lock: a sort saved meanwhile stays', function () {
    $stale = $this->owner->fresh();
    // Another request saves the Library's sort after this one read the person.
    $this->owner->fresh()->forceFill(['console_preferences' => ['library' => ['sort' => 'oldest']]])->save();

    V2DismissedBanners::dismiss($stale, (int) $this->owner->current_workspace_id, 'library', ['waiting' => 1]);
    expect($this->owner->fresh()->console_preferences)->toBe([
        'library' => ['sort' => 'oldest'],
        'dismissed' => [(string) $this->owner->current_workspace_id => ['library' => ['waiting' => 1]]],
    ])->and($stale->console_preferences['library']['sort'] ?? null)->toBe('oldest');
});
