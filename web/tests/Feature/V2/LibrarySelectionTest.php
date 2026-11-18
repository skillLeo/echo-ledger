<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The v2.0 Library's selection mode (docs/PLAN_V2_PARITY.md section 3): × to leave, the count, Select all, four main
 * actions (Move to project, Label, Export, Delete) and ⋮ with Organise, Export and Danger; Delete goes to Trash at once
 * with Undo. Classic keeps its own bar (ClassicGoldenTest).
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

it('shows the four main actions and ⋮ with Organise, Export and Danger, and × to leave', function () {
    $page = Livewire::test(ListCaptureSessions::class)->selectTableRecords($this->recordings->pluck('id')->all());

    $bar = collect($page->instance()->getTable()->getToolbarActions());
    expect($bar->map(fn ($a) => $a->getLabel())->all())->toBe(['Move to project', 'Label', 'Export', 'Delete', 'More']);
    $groups = collect($bar->last()->getActions());
    expect($groups->map(fn ($g) => $g->getLabel())->all())->toBe(['Organise', 'Export', 'Danger'])
        ->and(collect($groups[0]->getActions())->map(fn ($a) => $a->getLabel())->all())
        ->toBe(['Pin', 'Unpin', 'Keep forever', 'Stop keeping', 'Move to project', 'Add label', 'Remove label'])
        ->and($groups[2]->getActions()[0]->getColor())->toBe('danger');

    $this->get('/app/sessions')->assertOk()->assertSee('data-selection-exit', false)->assertSee('Leave the selection');
});

it('moves the selected recordings to Trash at once, offers Undo, and brings them back', function () {
    $ids = $this->recordings->take(2)->pluck('id')->all();

    Livewire::test(ListCaptureSessions::class)->callTableBulkAction('trash', $ids)->assertHasNoTableBulkActionErrors()
        ->assertNotified('2 recordings moved to Trash');
    expect(CaptureSession::query()->whereIn('id', $ids)->count())->toBe(0)
        ->and(CaptureSession::onlyTrashed()->whereIn('id', $ids)->count())->toBe(2);

    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', ids: $ids)->assertNotified('2 recordings are back');
    expect(CaptureSession::query()->whereIn('id', $ids)->count())->toBe(2);
});

it('brings back only the person\'s own recordings that Trash still keeps', function () {
    $other = Fx::session(Fx::user(), null, ['title' => 'Not mine']);
    $other->delete();
    $mine = $this->recordings->first();
    $mine->delete();

    Livewire::test(ListCaptureSessions::class)->dispatch('el-library-undo-delete', ids: [$mine->id, $other->id])->assertNotified('1 recording is back');
    expect(CaptureSession::query()->find($mine->id))->not->toBeNull()
        ->and(CaptureSession::withoutGlobalScopes()->onlyTrashed()->find($other->id))->not->toBeNull();
});

it('gives the new Library its keys and right-click, and only the new Library', function () {
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain('elV2LibraryKeys')->toContain('data-selection-delete')->toContain('data-selection-more')
        ->toContain("(e.ctrlKey || e.metaKey) && key === 'a'")->toContain("addEventListener('contextmenu'");
    $this->get('/app/settings/shortcuts')->assertOk()->assertSee('Select a range')->assertSee('Delete the selection (Undo in the notice)');

    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
    }
    $classic = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($classic)->not->toContain('elV2LibraryKeys')->not->toContain('data-selection-delete');
    $this->get('/app/settings/shortcuts')->assertOk()->assertDontSee('Select a range');
});
