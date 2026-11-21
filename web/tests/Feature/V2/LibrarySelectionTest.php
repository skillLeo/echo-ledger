<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Models\CaptureSession;
use App\Services\InterfaceFlags;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The v2.0 Library's selection mode (docs/PLAN_V2_PARITY.md section 3): × to leave, the count, Select all, four main
 * actions (Move to project, Label, Export, Delete) and ⋮ with Organise, Export and Danger; Delete goes to Recently deleted
 * at once with Undo. Classic keeps its own bar (ClassicGoldenTest).
 */

beforeEach(function () {
    $this->owner = Fx::user();
    $device = Fx::device($this->owner);
    $this->recordings = collect(range(1, 3))->map(fn (int $i) => Fx::session($this->owner, $device, ['title' => "Call {$i}"]));
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

it('shows the four main actions and ⋮ with Organise, Export ▸ and Delete last and red, in one row of selection actions, and × to leave', function () {
    $page = Livewire::test(ListCaptureSessions::class)->selectTableRecords($this->recordings->pluck('id')->all());

    $bar = collect($page->instance()->getTable()->getToolbarActions());
    expect($bar->map(fn ($a) => $a->getLabel())->all())->toBe(['Move to project', 'Label', 'Export', 'Delete', 'More']);
    // One row: Filament also draws the toolbar's copies above the table; each is hidden unless it is in the selection bar.
    foreach ($bar as $action) {
        expect($action->getExtraAttributes())->toHaveKey('x-bind:hidden', "\$el.closest('.v2-sel-actions') === null");
    }
    $label = fn ($a): string => (string) $a->getLabel();
    $groups = collect($bar->last()->getActions());
    // Each group opens with its heading, as the desktop's and the phone's menus.
    expect($groups->map(fn ($g) => $label(collect($g->getActions())->first()))->all())->toBe(['Organise', 'Export', 'Danger'])
        ->and($groups->map(fn ($g) => collect($g->getActions())->first()->getView())->unique()->all())->toBe(['filament.v2.menu-heading'])
        ->and(collect($groups[0]->getActions())->skip(1)->map($label)->values()->all())
        ->toBe(['Pin', 'Unpin', 'Mark important', 'Unmark important', 'Keep forever', 'Stop keeping', 'Move to project', 'Add label', 'Remove label', 'Transcribe…']);
    // Export: one Export ▸ sub-menu of every format, then Copy for AI.
    $export = collect($groups[1]->getActions())->skip(1)->values();
    expect($export->map($label)->all())->toBe(['Export', 'Copy for AI'])
        ->and($export[0])->toBeInstanceOf(BulkActionGroup::class)
        ->and($export[0]->hasDropdown())->toBeTrue()
        ->and(collect($export[0]->getActions())->map(fn ($a) => strip_tags($label($a)))->all())
        ->toBe(['Word (.docx)', 'Text (.txt)', 'Markdown (.md)', 'Subtitles (.srt)', 'Subtitles (.vtt)', 'Web page (.html)', 'JSON']);
    // Delete last, red.
    $danger = collect($groups[2]->getActions())->last();
    expect($groups->keys()->last())->toBe(2)->and($label($danger))->toBe('Delete')->and($danger->getColor())->toBe('danger');

    $this->get('/app/sessions')->assertOk()->assertSee('data-selection-exit', false)->assertSee('Leave the selection');
});

it('keeps each format\'s extension left to right and reads the menu in Urdu', function () {
    expect((string) CaptureSessionsTable::formatLabel('Word (.docx)'))->toBe('Word <bdi dir="ltr">(.docx)</bdi>')
        ->and(CaptureSessionsTable::formatLabel('JSON'))->toBe('JSON');
    $prefs = $this->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($this->owner->fresh(), 'web');
    app()->setLocale('ur');

    expect((string) CaptureSessionsTable::formatLabel('Word (.docx)'))->toBe('ورڈ <bdi dir="ltr">(.docx)</bdi>');
    $page = Livewire::test(ListCaptureSessions::class)->selectTableRecords($this->recordings->pluck('id')->all());
    $organise = collect(collect(collect($page->instance()->getTable()->getToolbarActions())->last()->getActions())[0]->getActions());
    expect($organise->map(fn ($a): string => (string) $a->getLabel())->all())
        ->toBe(['ترتیب', 'پن کریں', 'پن ہٹائیں', 'اہم نشان لگائیں', 'اہم کا نشان ہٹائیں', 'ہمیشہ رکھیں', 'ہمیشہ رکھنا بند کریں', 'پروجیکٹ میں منتقل کریں', 'لیبل لگائیں', 'لیبل ہٹائیں', 'ٹرانسکرائب کریں…']);
});

/** The notices sent so far, as JSON (read the way assertNotified reads them, so they are taken). */
function selectionNotices(): string
{
    app(NotificationMailbox::class)->flush();
    $notices = new Notifications;
    $notices->mount();
    $notices->pullNotificationsFromSession();

    return (string) json_encode($notices->notifications->toArray(), JSON_UNESCAPED_UNICODE);
}

it('moves the selected recordings to Recently deleted at once, offers Undo, and brings them back', function () {
    $ids = $this->recordings->take(2)->pluck('id')->all();

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('trash', $ids)->assertHasNoTableBulkActionErrors();
    $notices = selectionNotices();
    preg_match('/"token":"([A-Za-z0-9]{32})"/', $notices, $m);
    $token = $m[1] ?? '';
    expect($notices)->toContain('2 recordings moved to Recently deleted');
    expect(CaptureSession::query()->whereIn('id', $ids)->count())->toBe(0)
        ->and(CaptureSession::onlyTrashed()->whereIn('id', $ids)->count())->toBe(2);
    expect($token)->not->toBe('');

    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', token: $token)->assertNotified('2 recordings are back');
    expect(CaptureSession::query()->whereIn('id', $ids)->count())->toBe(2);
    // The token is spent: a second Undo brings nothing back and says Undo has expired.
    $this->recordings->first()->delete();
    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', token: $token)->assertNotified('Undo has expired');
    expect(CaptureSession::query()->find($this->recordings->first()->id))->toBeNull();
});

it('brings back only that batch, only the person\'s own, and only what they may restore', function () {
    $other = Fx::session(Fx::user(), null, ['title' => 'Not mine']);
    $other->delete();
    [$mine, $earlier] = [$this->recordings[0], $this->recordings[1]];
    $mine->delete();
    $earlier->delete();
    Cache::put(CaptureSessionsTable::undoKey('batch'), [(string) $mine->id, (string) $other->id], now()->addMinutes(5));

    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', token: 'batch');
    expect(selectionNotices())->toContain('1 recording is back')->toContain('"status":"success"');
    expect(CaptureSession::query()->find($mine->id))->not->toBeNull()
        ->and(CaptureSession::query()->find($earlier->id))->toBeNull()
        ->and(CaptureSession::withoutGlobalScopes()->onlyTrashed()->find($other->id))->not->toBeNull();
    // Ids sent from the browser are not a batch.
    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', ids: [$earlier->id])->assertNotified('Undo has expired');
    expect(CaptureSession::query()->find($earlier->id))->toBeNull();

    // A person the policy does not let restore gets nothing back.
    Gate::before(fn ($user, string $ability) => $ability === 'restore' ? false : null);
    Cache::put(CaptureSessionsTable::undoKey('denied'), [(string) $earlier->id], now()->addMinutes(5));
    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', token: 'denied');
    // Nothing came back: the notice is a warning, not a success.
    $notices = selectionNotices();
    expect($notices)->toContain('0 recordings are back')->toContain('"status":"warning"')->not->toContain('"status":"success"');
});

it('leaves a recording that is still recording or uploading where it is, and says so', function () {
    $busy = Fx::session($this->owner, null, ['title' => 'Still uploading', 'status' => SessionStatus::Uploading]);
    $ids = [$this->recordings[0]->id, $busy->id];

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('trash', $ids);
    expect(selectionNotices())->toContain('1 recording moved to Recently deleted')->toContain('1 recording is still recording or uploading and was left alone.');
    expect(CaptureSession::query()->find($busy->id))->not->toBeNull()
        ->and(CaptureSession::query()->find($this->recordings[0]->id))->toBeNull();
});

it('gives the new Library its keys and right-click, and only the new Library', function () {
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain('elV2LibraryKeys')->toContain('data-selection-delete')->toContain('data-selection-more')
        ->toContain('window.ElLibraryKeysCore')->toContain("addEventListener('contextmenu'");
    $this->get('/app/settings/shortcuts')->assertOk()->assertSee('Select a range')->assertSee('Delete the selection (Undo in the notice)');

    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
    }
    $classic = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($classic)->not->toContain('elV2LibraryKeys')->not->toContain('data-selection-delete');
    $this->get('/app/settings/shortcuts')->assertOk()->assertDontSee('Select a range');
});

it('offers Export as a sub-menu of every format, Web page (.html) too, and exports many as one zip', function () {
    foreach ($this->recordings->take(2) as $recording) {
        Fx::transcript($recording, 'Hello from '.$recording->title.'.');
    }
    $ids = $this->recordings->take(2)->pluck('id')->all();
    $page = Livewire::test(ListCaptureSessions::class)->selectTableRecords($ids);
    $export = collect($page->instance()->getTable()->getToolbarActions())->first(fn ($a) => $a->getLabel() === 'Export');
    expect(collect($export->getActions())->map(fn ($a) => strip_tags((string) $a->getLabel()))->all())
        ->toBe(['Word (.docx)', 'Text (.txt)', 'Markdown (.md)', 'Subtitles (.srt)', 'Subtitles (.vtt)', 'Web page (.html)', 'JSON']);

    $page->callTableBulkAction('exportHtml', $ids, data: ['view' => null])->assertHasNoTableBulkActionErrors()->assertFileDownloaded();
    $more = collect($page->instance()->getTable()->getToolbarActions())->last();
    expect(collect(collect($more->getActions())[0]->getActions())->map(fn ($a) => $a->getName())->all())->toContain('moveInMenu')
        ->and(collect(collect(collect($more->getActions())[1]->getActions())[1]->getActions())->map(fn ($a) => $a->getName())->all())->toContain('exportInMenuHtml');
});

it('decides the keys and the mouse as the Node test says (Ctrl/Cmd+A, Shift-click, Delete, right-click, never while typing)', function () {
    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/library-keys-core.test.mjs']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

it('starts a new page of the list with nothing selected', function () {
    Livewire::test(ListCaptureSessions::class)->call('gotoPage', 2, 'page')->assertDispatched('deselectAllTableRecords');
});

it('starts another place, folder or search of the list with nothing selected', function () {
    $ids = $this->recordings->pluck('id')->all();
    Livewire::test(ListCaptureSessions::class)->selectTableRecords($ids)->call('openPlace', 'today')->assertDispatched('deselectAllTableRecords');
    Livewire::test(ListCaptureSessions::class)->selectTableRecords($ids)->call('chooseFolder', 'whatsapp')->assertDispatched('deselectAllTableRecords');
    Livewire::test(ListCaptureSessions::class)->selectTableRecords($ids)->set('tableSearch', 'Call 1')->assertDispatched('deselectAllTableRecords');
});
