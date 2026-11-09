<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Support\LibraryView;
use App\Models\CaptureSession;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(AdminTestHelpers::class);

/* The console Library decluttered (since v1.6, P5.2): one compact toolbar, the bulk bar only while selected, empty states. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin();
});

it('names Group by and Sort by exactly as the phone and the desktop do', function () {
    expect(array_values(LibraryView::GROUPS))->toBe(['Date', 'App / Type', 'Project', 'Language', 'Status', 'Device', 'None'])
        ->and(array_values(LibraryView::SORTS))->toBe(['Newest', 'Oldest', 'Longest', 'Shortest', 'Name A–Z', 'Recently opened']);

    $html = $this->panelComponent(ListCaptureSessions::class)->html();
    foreach ([...LibraryView::GROUPS, ...LibraryView::SORTS] as $label) {
        expect($html)->toContain(e($label));
    }
});

it('has one toolbar row and no rows of chips or second Sort by', function () {
    CaptureSession::factory()->create(['user_id' => $this->admin->id, 'source_app' => 'whatsapp']);
    $html = $this->panelComponent(ListCaptureSessions::class)->html();

    expect(substr_count($html, 'class="el-library-toolbar"'))->toBe(1)
        ->and($html)->not->toContain('el-chips el-view-chips')->not->toContain('el-folder-chips')->not->toContain('el-library-menus')
        ->not->toContain('fi-ta-sorting-settings')
        // The folders are in the menu, with their counts; Shared with me opens its own page.
        ->toContain('data-option="folder-all"')->toContain('data-option="folder-whatsapp"')->toContain('data-option="folder-inbox"');
});

it('picks a folder from the menu and keeps it when it is picked again; All folders clears it', function () {
    $wa = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'source_app' => 'whatsapp']);
    $zoom = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'source_app' => 'zoom']);

    $page = $this->panelComponent(ListCaptureSessions::class)->call('chooseFolder', 'whatsapp');
    $page->assertSet('folder', 'whatsapp')->assertCanSeeTableRecords([$wa])->assertCanNotSeeTableRecords([$zoom]);
    expect($page->html())->toMatch('/class="el-tool el-on" data-menu="folder"/');

    $page->call('chooseFolder', 'whatsapp')->assertSet('folder', 'whatsapp')
        ->call('chooseFolder', null)->assertSet('folder', null)->assertCanSeeTableRecords([$wa, $zoom])
        // Not a folder of the workspace: ignored.
        ->call('chooseFolder', 'shared')->assertSet('folder', null)->call('chooseFolder', 'nowhere')->assertSet('folder', null);
});

it('keeps the bulk actions in a bar that shows only while something is selected', function () {
    CaptureSession::factory()->create(['user_id' => $this->admin->id]);
    $page = $this->panelComponent(ListCaptureSessions::class);
    $html = $page->html();

    // The bar is the table's selection indicator (x-show while selected) with the actions inside it.
    expect($html)->toMatch('/x-show="getSelectedRecordsCount\(\)"[^>]*class="fi-ta-selection-indicator"/s')
        ->toContain('el-bulk-actions')->toContain('1 selected|:count selected')->toContain('Clear');
    // Drawn inside the bar, in order: Move, Label, Export, Delete.
    $bar = substr($html, (int) strpos($html, 'class="el-bulk-actions"'));
    $at = fn (string $name): int|false => strpos($bar, "mountAction('{$name}', {}, JSON.parse('{\\u0022table\\u0022:true,\\u0022bulk\\u0022:true}')");
    expect($at('move'))->toBeInt()->and($at('label'))->toBeGreaterThan($at('move'))
        ->and($at('export'))->toBeGreaterThan($at('label'))->and($at('delete'))->toBeGreaterThan($at('export'));

    $bar = CaptureSessionsTable::bulkBar();
    $labels = array_map(fn ($a): string => (string) $a->getLabel(), $bar);
    expect($labels)->toBe(['Move', 'Label', 'Export', 'Delete', 'More'])
        ->and($bar[1])->toBeInstanceOf(BulkActionGroup::class)
        ->and(array_map(fn (BulkAction $a): string => $a->getName(), $bar[1]->getActions()))->toBe(['label', 'unlabel'])
        ->and(array_map(fn (BulkAction $a): string => $a->getName(), $bar[4]->getActions()))->toBe(['pin', 'unpin', 'keep', 'unkeep']);
});

it('still moves, labels and deletes only the selected recordings from the bar', function () {
    $a = CaptureSession::factory()->create(['user_id' => $this->admin->id]);
    $b = CaptureSession::factory()->create(['user_id' => $this->admin->id]);
    $client = Fx::client($this->admin, 'Acme');

    $this->panelComponent(ListCaptureSessions::class)
        ->callTableBulkAction('move', [$a], ['client_id' => $client->id])
        ->callTableBulkAction('label', [$a, $b], ['label' => 'Invoices'])
        ->callTableBulkAction('delete', [$b]);

    expect($a->fresh()->client_id)->toBe($client->id)->and($a->fresh()->labelList())->toBe(['Invoices'])
        ->and(CaptureSession::query()->find($b->id))->toBeNull();
});

it('never lets the bar touch another person\'s recording', function () {
    $other = Fx::user();
    $theirs = Fx::session($other);
    $mine = CaptureSession::factory()->create(['user_id' => $this->admin->id]);

    $page = $this->panelComponent(ListCaptureSessions::class)->assertCanNotSeeTableRecords([$theirs]);
    // A key from someone else's library is not a record of this list.
    $page->set('selectedTableRecords', [$theirs->id, $mine->id])->callTableBulkAction('pin', [$mine]);

    expect(unscoped(fn () => CaptureSession::query()->find($theirs->id))->pinned)->toBeFalse()
        ->and($mine->fresh()->pinned)->toBeTrue();
});

it('says why the list is empty, with one next step', function (string $tab, ?string $folder, string $heading, string $action) {
    CaptureSession::factory()->create(['user_id' => $this->admin->id, 'source_app' => 'zoom', 'started_at' => now()->subDays(30)]);
    $page = $this->panelComponent(ListCaptureSessions::class)->set('activeTab', $tab);
    if ($folder !== null) {
        $page->call('chooseFolder', $folder);
    }

    expect($page->html())->toContain($heading)->toContain($action);
})->with([
    'failed uploads' => ['failed', null, 'No failed uploads', 'Show all recordings'],
    'pinned' => ['pinned', null, 'Nothing pinned', 'Show all recordings'],
    'today' => ['today', null, 'Nothing recorded today', 'Show all recordings'],
    'waiting' => ['waiting', null, 'No calls waiting for a decision', 'Show all recordings'],
    'a folder' => ['all', 'whatsapp', 'No recordings in WhatsApp', 'Show all recordings'],
]);

it('shows everything again from the empty state', function () {
    $zoom = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'source_app' => 'zoom']);
    $page = $this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'pinned')->call('chooseFolder', 'whatsapp')
        ->assertCanNotSeeTableRecords([$zoom]);

    $page->call('showAllRecordings')->assertSet('activeTab', 'all')->assertSet('folder', null)->assertCanSeeTableRecords([$zoom]);
});

it('greets an empty Library with Upload and Sign in a device', function () {
    $html = $this->panelComponent(ListCaptureSessions::class)->html();

    expect($html)->toContain('No recordings yet')->toContain('Upload a voice note')->toContain('Sign in a device')
        ->not->toContain('Show all recordings');
});

it('says a search found nothing and offers to clear it', function () {
    CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Budget']);

    expect($this->panelComponent(ListCaptureSessions::class)->searchTable('zzzz')->html())
        ->toContain('No recordings match your search')->toContain('Clear search and filters');
});

it('lets a group header\'s box only select: its click never reaches the header that folds', function () {
    CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Completed]);
    $html = $this->panelComponent(ListCaptureSessions::class)->html();

    // Filament folds on a click anywhere on the header; the toolbar installs one listener that stops the box's click.
    expect($html)->toContain('fi-ta-group-checkbox')->toContain('fi-collapsible')
        ->toContain('window.elGroupBoxOnlySelects')->toContain(".closest('.el-library .fi-ta-group-checkbox')")
        ->toContain('ev.stopPropagation()');
});
