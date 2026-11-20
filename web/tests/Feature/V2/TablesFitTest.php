<?php

declare(strict_types=1);

use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\Shares;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\RelationManagers\ShareLinksRelationManager;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\SettingsContext;
use App\Models\CaptureSession;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Livewire\Livewire;

/*
 * W-201: the four wide tables fit the page at 1024 px in the new frame, with nothing taken away. The row's actions are
 * one ⋮ Actions menu (the same actions, labels and confirmations as classic, the red one last), and the columns that
 * made the table too wide are hidden at first but stay under Columns. Classic keeps its buttons and every column shown
 * (ClassicTablesGoldenTest holds its HTML byte for byte).
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

/** The table of one of the four, in the new frame or in classic. */
function fitTable(object $test, string $which, bool $v2): Table
{
    webParityDesign($v2);
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($test->owner->fresh(), 'web');
    $component = match ($which) {
        'share-links' => Livewire::test(ShareLinksRelationManager::class, [
            'ownerRecord' => CaptureSession::query()->where('title', 'Payment terms for the March shipment')->firstOrFail(),
            'pageClass' => ViewCaptureSession::class,
        ]),
        'shares' => Livewire::test(Shares::class),
        'devices' => Livewire::test(ListDevices::class),
        'request-links' => Livewire::test(RequestLinksPage::class),
    };

    return $component->instance()->getTable();
}

/** The row's actions as "name:label:colour:asks" in order, and whether they are one ⋮ group. */
function fitActions(Table $table): array
{
    $actions = $table->getRecordActions();
    $group = count($actions) === 1 && $actions[0] instanceof ActionGroup ? $actions[0] : null;
    $list = $group !== null ? array_values($group->getActions()) : array_values($actions);

    return [$group !== null, array_map(fn (Action $a): string => $a->getName().':'.$a->getLabel().':'.($a->getColor() ?? 'primary').':'.($a->isConfirmationRequired() ? 'asks' : 'at once'), $list)];
}

/** The columns hidden at first (each still under Columns), and the ones under Columns at all. */
function fitColumns(Table $table): array
{
    $columns = collect($table->getColumns());

    return [
        $columns->filter(fn ($c): bool => $c->isToggleable() && $c->isToggledHiddenByDefault())->keys()->values()->all(),
        $columns->filter(fn ($c): bool => $c->isToggleable())->keys()->values()->all(),
    ];
}

dataset('wide tables', [
    'recording › share links' => ['share-links',
        ['log:Access log:gray:at once', 'revoke:Revoke:danger:asks'],
        ['allow_download', 'created_by']],
    'my links' => ['shares',
        ['log:Access log:gray:at once', 'revoke:Revoke:danger:asks'],
        ['allow_download', 'last_viewed_at', 'created_at']],
    'devices' => ['devices',
        ['rename:Rename:gray:at once', 'signInAgain:Sign in again:gray:at once', 'signOut:Sign out:danger:asks', 'delete:Remove from list:danger:asks'],
        ['app_version', 'created_at']],
    'request links' => ['request-links',
        ['revoke:Revoke:danger:asks'],
        ['created_at']],
]);

it('puts the row\'s actions in one ⋮ menu and hides the wide columns at first in the new frame, with nothing taken away', function (string $which, array $actions, array $hidden) {
    $v2 = fitTable($this, $which, true);
    [$grouped, $list] = fitActions($v2);
    [$hiddenAtFirst, $inColumns] = fitColumns($v2);
    expect($grouped)->toBeTrue()->and($list)->toBe($actions)
        ->and($hiddenAtFirst)->toBe($hidden)->and($inColumns)->toBe($hidden)
        // Every classic column is still there, under Columns when hidden.
        ->and(array_keys($v2->getColumns()))->toBe(array_keys(fitTable($this, $which, false)->getColumns()));
})->with('wide tables');

it('keeps classic\'s buttons on the row and every column shown, with no Columns menu', function (string $which, array $actions) {
    $classic = fitTable($this, $which, false);
    [$grouped, $list] = fitActions($classic);
    [$hiddenAtFirst, $inColumns] = fitColumns($classic);
    expect($grouped)->toBeFalse()->and($list)->toBe($actions)
        ->and($hiddenAtFirst)->toBe([])->and($inColumns)->toBe([]);
})->with('wide tables');

it('shows the request links\' Open until without the time in the new frame, the time in the tooltip', function () {
    $html = (string) $this->actingAs($this->owner->fresh(), 'web')->get($this->pages['request-links'])->getContent();
    webParityDesign(true);
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $v2 = (string) $this->actingAs($this->owner->fresh(), 'web')->get($this->pages['request-links'])->getContent();
    expect($html)->toContain('Nov 24, 2026 · 11:42 AM')
        ->and($v2)->toContain('24 Nov 2026')->not->toContain('Nov 24, 2026 · 11:42 AM')->toContain('24 Nov 2026 · 11:42 AM');
});
