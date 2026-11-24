<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Models\SessionOpen;
use App\Models\User;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('marks as unread and as read again: only the person\'s own open, and Mark as read only when every one is unread', function () {
    $mate = User::factory()->create();
    SessionOpen::mark($this->a->id, $this->owner->id);
    SessionOpen::mark($this->a->id, $mate->id);
    $opened = fn (CaptureSession $s): bool => SessionOpen::query()->where('capture_session_id', $s->id)->where('user_id', $this->owner->id)->exists();
    $page = Livewire::test(ListCaptureSessions::class);

    // One read, one not: Mark as unread takes back the person's own open, and leaves another person's.
    $page->call('menuAction', 'recording.unread', [$this->a->id, $this->b->id]);
    expect($opened($this->a))->toBeFalse()->and($opened($this->b))->toBeFalse()
        ->and(SessionOpen::query()->where('capture_session_id', $this->a->id)->pluck('user_id')->all())->toBe([$mate->id]);
    // Every one unread: Mark as read.
    $page->call('menuAction', 'recording.unread', [$this->a->id, $this->b->id]);
    expect($opened($this->a))->toBeTrue()->and($opened($this->b))->toBeTrue();
    // It never touches the recording.
    expect($this->a->fresh()->updated_at->equalTo($this->a->updated_at))->toBeTrue();
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

    SessionOpen::mark($foreign->id, $this->owner->id);
    $page->call('menuAction', 'recording.pin', [$foreign->id]);
    $page->call('menuAction', 'recording.unread', [$foreign->id]);
    $page->call('menuAction', 'recording.labels', [$foreign->id, $this->a->id], 'Leak');
    $page->call('menuAction', 'recording.moveToTrash', [$foreign->id]);
    expect($foreign->fresh()->pinned)->toBeFalse()
        ->and(SessionOpen::query()->where('capture_session_id', $foreign->id)->count())->toBe(1)
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
        ->assertActionMounted('menuRename')->assertSet('menuRecord', (string) $this->a->id)
        ->assertMountedActionModalSee('Rename recording');
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
        ->and($data['runnable'])->toContain('recording.pin', 'recording.unread', 'recording.labels', 'file.rename', 'recording.moveToTrash');
});

it('draws each row with what its menu needs: pinned, important, its state, its project and its labels', function () {
    $acme = Fx::client($this->owner, 'Acme');
    $this->a->forceFill(['pinned' => true, 'client_id' => $acme->id])->save();
    $this->a->labels()->create(['label' => 'Q4']);

    Livewire::test(ListCaptureSessions::class)
        ->assertSeeHtml('data-recording="'.$this->a->id.'" data-important="0" data-pinned="1"')
        ->assertSeeHtml('data-opened="0"')
        ->assertSeeHtml('data-project="'.$acme->id.'"')
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

it('shares one recording through the console\'s own actions: Copy link makes a link, Share with people sends it, WhatsApp opens its panel', function () {
    Fx::readyRecording($this->a, 'audio of call A');
    $page = Livewire::test(ListCaptureSessions::class);

    $page->call('menuAction', 'file.copyLink', [$this->a->id])->assertActionMounted('menuShare')->assertMountedActionModalSee('Share this recording');
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.shareWithPeople', [$this->a->id])->assertActionMounted('menuSend');
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.sendToWhatsApp', [$this->a->id])->assertActionMounted('menuWhatsapp')
        ->assertMountedActionModalSee('Send to WhatsApp');
    // One at a time, as the console shares; and only the person's own.
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.shareWithPeople', [$this->a->id, $this->b->id])->assertActionNotMounted();
    $foreign = Fx::session(Fx::user(), null, ['title' => 'Not mine']);
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.sendToWhatsApp', [$foreign->id])->assertActionNotMounted();
    // Which recording the dialog is for is the server's to say: the browser cannot set it.
    expect(fn () => Livewire::test(ListCaptureSessions::class)->set('menuRecord', (string) $foreign->id))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('offers Copy link and WhatsApp only for a recording with audio, as the recording page does', function () {
    $silent = Fx::session($this->owner, null, ['title' => 'Silent']);
    $silent->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed,
        'error_code' => TranscriptErrorCode::NoAudio->value]);

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.copyLink', [$silent->id])->assertActionNotMounted();
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.sendToWhatsApp', [$silent->id])->assertActionNotMounted();
    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'file.shareWithPeople', [$silent->id])->assertActionMounted('menuSend');
    expect(Livewire::test(ListCaptureSessions::class)->html())->toContain('data-recording="'.$silent->id.'"')
        ->and(preg_match('~data-recording="'.$silent->id.'"[^>]*data-audio="0"~', Livewire::test(ListCaptureSessions::class)->html()))->toBe(1);
});

it('takes no more than 200 at a time: more says how many to unselect and does nothing', function () {
    $device = Fx::device($this->owner);
    $ids = collect(range(1, 203))->map(fn (int $i): string => (string) Fx::session($this->owner, $device, ['title' => "Bulk {$i}"])->id)->all();

    Livewire::test(ListCaptureSessions::class)->call('menuAction', 'recording.pin', $ids)->assertNotified('Up to 200 at a time: unselect 3');
    expect(CaptureSession::query()->whereIn('id', $ids)->where('pinned', true)->count())->toBe(0);
});

it('leaves the person\'s own selection as it was after a menu on another row, done or cancelled', function () {
    $before = ['selectedTableRecords' => [(string) $this->b->id], 'deselectedTableRecords' => [], 'isTrackingDeselectedTableRecords' => false];

    // Done (Move to trash has no question): the row goes, the selection stays, and the browser is not told to clear it.
    Livewire::test(ListCaptureSessions::class)->set('selectedTableRecords', [(string) $this->b->id])
        ->call('menuAction', 'recording.moveToTrash', [$this->a->id])
        ->assertSet('selectedTableRecords', $before['selectedTableRecords'])->assertNotDispatched('deselectAllTableRecords');
    expect(CaptureSession::query()->find($this->a->id))->toBeNull();

    // Cancelled (Export asks which view): the selection comes back when its dialog closes.
    Livewire::test(ListCaptureSessions::class)->set('selectedTableRecords', [(string) $this->b->id])
        ->call('menuAction', 'recording.exportAs', [$this->b->id, Fx::session($this->owner, null, ['title' => 'C'])->id], 'txt')
        ->assertActionMounted(TestAction::make('exportInMenuTxt')->table()->bulk())
        ->call('unmountAction')
        ->assertSet('selectedTableRecords', $before['selectedTableRecords']);

    // The menu of the selection itself ends as the selection bar's actions do: the selection is cleared.
    Livewire::test(ListCaptureSessions::class)->set('selectedTableRecords', [(string) $this->b->id])
        ->call('menuAction', 'recording.moveToTrash', [$this->b->id], null, true)
        ->assertDispatched('deselectAllTableRecords');
});

it('opens the same menu from the row\'s ⋯ as from a right-click: Filament\'s group is gone from the new Library\'s rows', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    $actions = collect($page->instance()->getTable()->getRecordActions());
    expect($actions->filter(fn ($a): bool => $a instanceof Action)->map(fn (Action $a): string => (string) $a->getName())->values()->all())->toContain('rowMenu')
        ->and($actions->filter(fn ($a): bool => $a instanceof ActionGroup)->every(fn (ActionGroup $g): bool => $g->isHidden()))->toBeTrue();
    $html = $page->html();
    expect($html)->toContain('window.elV2ContextMenu.openFromButton($el, $event)')->toContain('data-row-menu');

    // The classic Library keeps its own.
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
    }
    app('auth')->forgetGuards();
    $this->actingAs($this->owner->fresh(), 'web');
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->not->toContain('openFromButton');
});
