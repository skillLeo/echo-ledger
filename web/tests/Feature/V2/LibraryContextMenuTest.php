<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * UX-22 (PLAN_V2_1_POLISH 3b) on the web console: a Library row's right-click menu (filament.v2.context-menu) sends its
 * pick to the page (ListCaptureSessions::menuAction) with the recordings it was opened on. Pin, Mark as important,
 * Labels › and Move to project › change them at once, each a toggle decided from what they are now; Rename…, Export ›
 * a format, Transcribe and Move to trash open (or run) the console's own actions for them. Only the person's own
 * recordings of this workspace, never from a read-only console.
 */

beforeEach(function () {
    $this->owner = Fx::user();
    $device = Fx::device($this->owner);
    [$this->a, $this->b] = collect(['Call A', 'Call B'])->map(fn (string $t) => Fx::session($this->owner, $device, ['title' => $t]))->all();
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

/** The labels of [$session], in lower case. */
function menuLabels(CaptureSession $session): array
{
    return $session->fresh()->labels->map(fn ($l): string => mb_strtolower($l->label))->sort()->values()->all();
}

it('pins and marks important one or several: the rest follow suit until every one is, then the menu takes it off', function () {
    $this->a->forceFill(['pinned' => true])->save();
    $page = Livewire::test(ListCaptureSessions::class);

    // One pinned, one not: Pin pins both (the menu says Pin unless every one is pinned).
    $page->call('menuAction', 'recording.pin', [$this->a->id, $this->b->id]);
    expect($this->a->fresh()->pinned)->toBeTrue()->and($this->b->fresh()->pinned)->toBeTrue();
    // Both pinned: Unpin.
    $page->call('menuAction', 'recording.pin', [$this->a->id, $this->b->id]);
    expect($this->a->fresh()->pinned)->toBeFalse()->and($this->b->fresh()->pinned)->toBeFalse();

    $page->call('menuAction', 'recording.important', [$this->b->id]);
    expect($this->b->fresh()->important)->toBeTrue()->and($this->a->fresh()->important)->toBeFalse();
    $page->call('menuAction', 'recording.important', [$this->b->id]);
    expect($this->b->fresh()->important)->toBeFalse();
});

it('ticks a label on or off: on for all unless every one has it, any spelling of it', function () {
    $this->a->labels()->create(['label' => 'Q4']);
    $page = Livewire::test(ListCaptureSessions::class);

    $page->call('menuAction', 'recording.labels', [$this->a->id, $this->b->id], 'Q4');
    expect(menuLabels($this->a))->toBe(['q4'])->and(menuLabels($this->b))->toBe(['q4']);
    $page->call('menuAction', 'recording.labels', [$this->a->id, $this->b->id], 'q4');
    expect(menuLabels($this->a))->toBe([])->and(menuLabels($this->b))->toBe([]);
    // A label is a pick's value; without one nothing happens.
    $page->call('menuAction', 'recording.labels', [$this->a->id]);
    expect(menuLabels($this->a))->toBe([]);
});

it('moves to one of the person\'s projects, or to none; another person\'s project is refused and nothing moves', function () {
    $acme = Fx::client($this->owner, 'Acme');
    $theirs = Fx::client(Fx::user(), 'Not mine');
    $page = Livewire::test(ListCaptureSessions::class);

    $page->call('menuAction', 'recording.moveToProject', [$this->a->id, $this->b->id], (string) $acme->id);
    expect($this->a->fresh()->client_id)->toBe($acme->id)->and($this->b->fresh()->client_id)->toBe($acme->id);
    $page->call('menuAction', 'recording.moveToProject', [$this->a->id], 'none');
    expect($this->a->fresh()->client_id)->toBeNull()->and($this->b->fresh()->client_id)->toBe($acme->id);
    $page->call('menuAction', 'recording.moveToProject', [$this->b->id], (string) $theirs->id)->assertNotified('Project not found.');
    expect($this->b->fresh()->client_id)->toBe($acme->id);
});

it('changes only the person\'s own recordings of this workspace, whatever ids the browser sends', function () {
    $foreign = Fx::session(Fx::user(), null, ['title' => 'Not mine']);
    $page = Livewire::test(ListCaptureSessions::class);

    $page->call('menuAction', 'recording.pin', [$foreign->id]);
    $page->call('menuAction', 'recording.labels', [$foreign->id, $this->a->id], 'Leak');
    $page->call('menuAction', 'recording.moveToTrash', [$foreign->id]);
    expect($foreign->fresh()->pinned)->toBeFalse()
        ->and(menuLabels($foreign))->toBe([])
        ->and(CaptureSession::withoutGlobalScopes()->find($foreign->id)?->deleted_at)->toBeNull()
        ->and(menuLabels($this->a))->toBe(['leak']);
});

it('changes nothing from a read-only console', function () {
    $workspaceId = (int) $this->owner->current_workspace_id;
    $member = Fx::user();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($workspaceId), $member, WorkspaceRole::Member);
    $member->forceFill(['current_workspace_id' => $workspaceId])->save();
    $theirs = Fx::session($member, null, ['title' => 'Member call', 'workspace_id' => $workspaceId]);
    $this->actingAs($member->fresh(), 'web');

    $page = Livewire::test(ListCaptureSessions::class);
    foreach (['recording.pin', 'recording.important', 'recording.moveToTrash'] as $action) {
        $page->call('menuAction', $action, [$theirs->id]);
    }
    $page->call('menuAction', 'recording.labels', [$theirs->id], 'Q4');
    $theirs->refresh();
    expect($theirs->pinned)->toBeFalse()->and($theirs->important)->toBeFalse()->and(menuLabels($theirs))->toBe([])
        ->and(CaptureSession::withoutGlobalScopes()->find($theirs->id)?->deleted_at)->toBeNull();
});

it('has no such menu in the classic Library', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
    }

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.pin', [$this->a->id])->assertDontSeeHtml('data-v2-menu-data');
    expect($this->a->fresh()->pinned)->toBeFalse();
});

it('opens Rename for one recording, Transcribe and the format\'s Export for what it was opened on, and moves it to trash with Undo', function () {
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.rename', [$this->a->id])
        ->assertActionMounted(TestAction::make('rename')->table($this->a));
    // Rename is for one: with several nothing opens.
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.rename', [$this->a->id, $this->b->id])->assertActionNotMounted();

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.exportAs', [$this->a->id, $this->b->id], 'txt')
        ->assertActionMounted(TestAction::make('exportInMenuTxt')->table()->bulk())
        ->assertSet('selectedTableRecords', [(string) $this->a->id, (string) $this->b->id]);
    // A format the console has not: nothing opens.
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.exportAs', [$this->a->id], 'exe')->assertActionNotMounted();

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.transcribe', [$this->b->id])
        ->assertActionMounted(TestAction::make('transcribeInMenu')->table()->bulk());

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.moveToTrash', [$this->a->id, $this->b->id])
        ->assertNotified('2 recordings moved to Recently deleted');
    expect(CaptureSession::query()->whereIn('id', [$this->a->id, $this->b->id])->count())->toBe(0);
});

it('leaves a recording that is still uploading out of Move to trash, as the selection bar does', function () {
    $busy = Fx::session($this->owner, null, ['title' => 'Still uploading', 'status' => SessionStatus::Uploading]);

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.moveToTrash', [$busy->id]);
    expect(CaptureSession::query()->find($busy->id))->not->toBeNull();
});

it('gives the menu its choices: the person\'s labels and projects, the export formats, and the words it needs', function () {
    $this->a->labels()->create(['label' => 'Q4']);
    Fx::client($this->owner, 'Acme');
    Fx::client(Fx::user(), 'Not mine');
    Fx::session(Fx::user(), null, ['title' => 'x'])->labels()->create(['label' => 'Leak']);

    $data = Livewire::test(ListCaptureSessions::class)->instance()->menuData();

    expect(array_column($data['labels'], 'label'))->toBe(['Q4'])
        ->and(array_column($data['projects'], 'label'))->toBe(['Acme'])
        ->and(array_column($data['formats'], 'value'))->toBe(['docx', 'txt', 'md', 'srt', 'vtt', 'html', 'json'])
        ->and($data['words']['many'])->toBe(':count recordings')
        ->and($data['runnable'])->toContain('recording.pin', 'recording.labels', 'file.rename', 'recording.moveToTrash')
        // Mark as unread waits for its endpoint (contract 2.0.0-t) on the web.
        ->not->toContain('recording.unread');
});

it('draws each row with what its menu needs: pinned, important, its state, its project and its labels', function () {
    $acme = Fx::client($this->owner, 'Acme');
    $this->a->forceFill(['pinned' => true, 'client_id' => $acme->id])->save();
    $this->a->labels()->create(['label' => 'Q4']);

    Livewire::test(ListCaptureSessions::class)
        ->assertSeeHtml('data-recording="'.$this->a->id.'" data-important="0" data-pinned="1"')
        ->assertSeeHtml('data-client="'.$acme->id.'"')
        ->assertSeeHtml('data-labels="[&quot;q4&quot;]"')
        ->assertSeeHtml('data-v2-menu-data');
});

it('says the menu in the console\'s language, from the one action list\'s words', function () {
    $page = Livewire::test(ListCaptureSessions::class)->instance();
    app()->setLocale('ur');

    $data = $page->menuData();

    expect($data['say'])->toMatchArray(['Pin' => 'پن کریں', 'Unpin' => 'پن ہٹائیں', 'Move to trash' => 'ردی میں ڈالیں'])
        ->and($data['words']['many'])->toBe(':count ریکارڈنگز')
        ->and(collect($data['actions'])->pluck('id')->sort()->values()->all())->toBe(collect(ListCaptureSessions::MENU_ACTIONS)->sort()->values()->all());
});

it('gives the new Library its menu, with Shift+F10 and the Menu key, and the classic one none', function () {
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain('window.ElContextMenuCore')->toContain('window.elV2ContextMenu')->toContain("e.key === 'ContextMenu'")
        ->toContain('data-v2-menu-data')->toContain('data-icon="LabelIcon"');

    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
    }
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->not->toContain('ElContextMenuCore')->not->toContain('data-v2-menu-data');
});
