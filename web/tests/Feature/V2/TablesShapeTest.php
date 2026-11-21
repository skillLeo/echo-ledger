<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\Shares;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\RelationManagers\ShareLinksRelationManager;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\SettingsContext;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Table;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * W-201, the tables' shape: in the new frame the four wide tables (a recording's Share links, My links, Devices,
 * Request links) have the row's actions in one ⋮ menu under Filament's own "Actions" label (the same actions, labels,
 * colours, confirmations and order as classic, the red ones last), and the columns that made them too wide are hidden
 * at first but stay under Columns; their words and dates are in the console's language, English or Urdu. Classic keeps
 * its buttons and every column shown (ClassicTablesGoldenTest holds its HTML byte for byte). The widths themselves are
 * measured in a browser by Support/table-widths.mjs (docs/v2/design-review/W201/widths.md).
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

/** One of the four tables, in the new frame (English or Urdu) or in classic, for $user (the world's owner by default). */
function shapeTable(object $test, string $which, bool $v2, string $language = 'en', ?User $user = null): Table
{
    webParityDesign($v2);
    $user ??= $test->owner;
    $prefs = is_array($user->console_preferences) ? $user->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $user->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($user->fresh(), 'web');
    app()->setLocale($v2 ? $language : 'en');

    return shapeComponent($which)->instance()->getTable();
}

function shapeComponent(string $which): mixed
{
    return match ($which) {
        'share-links' => Livewire::test(ShareLinksRelationManager::class, [
            'ownerRecord' => CaptureSession::query()->where('title', 'Payment terms for the March shipment')->firstOrFail(),
            'pageClass' => ViewCaptureSession::class,
        ]),
        'shares' => Livewire::test(Shares::class),
        'devices' => Livewire::test(ListDevices::class),
        'request-links' => Livewire::test(RequestLinksPage::class),
    };
}

/** The row's actions as "name:label:colour:asks" in order, whether they are one ⋮ group, and the group's label. */
function shapeActions(Table $table): array
{
    $actions = $table->getRecordActions();
    $group = count($actions) === 1 && $actions[0] instanceof ActionGroup ? $actions[0] : null;
    $list = $group !== null ? array_values($group->getActions()) : array_values($actions);

    return [$group?->getLabel(), array_map(fn (Action $a): string => $a->getName().':'.$a->getLabel().':'.($a->getColor() ?? 'primary').':'.($a->isConfirmationRequired() ? 'asks' : 'at once'), $list)];
}

/** The columns hidden at first (each still under Columns), the ones under Columns at all, and every column's header. */
function shapeColumns(Table $table): array
{
    $columns = collect($table->getColumns());

    return [
        $columns->filter(fn ($c): bool => $c->isToggleable() && $c->isToggledHiddenByDefault())->keys()->values()->all(),
        $columns->filter(fn ($c): bool => $c->isToggleable())->keys()->values()->all(),
        $columns->map(fn ($c): string => (string) $c->getLabel())->values()->all(),
    ];
}

dataset('wide tables', [
    'recording › share links' => ['share-links',
        ['log:Access log:gray:at once', 'revoke:Revoke:danger:asks'],
        ['log:رسائی کا ریکارڈ:gray:at once', 'revoke:منسوخ کریں:danger:asks'],
        ['allow_download', 'created_by'],
        ['Made', 'Status', 'Expires', 'Who can open it', 'Downloads', 'Views', 'Last opened', 'Made by'],
        ['بنایا گیا', 'حالت', 'میعاد', 'کون کھول سکتا ہے', 'ڈاؤن لوڈز', 'ویوز', 'آخری بار کھولا گیا', 'بنانے والا']],
    'my links' => ['shares',
        ['log:Access log:gray:at once', 'revoke:Revoke:danger:asks'],
        ['log:رسائی کا ریکارڈ:gray:at once', 'revoke:منسوخ کریں:danger:asks'],
        ['allow_download', 'last_viewed_at', 'created_at'],
        ['Shared', 'Who can open it', 'Status', 'Downloads', 'Expires', 'Views', 'Last opened', 'Made'],
        ['شیئر شدہ', 'کون کھول سکتا ہے', 'حالت', 'ڈاؤن لوڈز', 'میعاد', 'ویوز', 'آخری بار کھولا گیا', 'بنایا گیا']],
    'devices' => ['devices',
        ['rename:Rename:gray:at once', 'signInAgain:Sign in again:gray:at once', 'signOut:Sign out:danger:asks', 'delete:Remove from list:danger:asks'],
        ['rename:نام بدلیں:gray:at once', 'signInAgain:دوبارہ سائن اِن کریں:gray:at once', 'signOut:سائن آؤٹ:danger:asks', 'delete:فہرست سے ہٹائیں:danger:asks'],
        ['app_version', 'created_at'],
        ['Name', 'Status', 'Type', 'Platform', 'App', 'Recordings', 'Last seen', 'Signed in'],
        ['نام', 'حالت', 'قسم', 'پلیٹ فارم', 'ایپ', 'ریکارڈنگز', 'آخری بار دیکھی گئی', 'سائن اِن']],
    'request links' => ['request-links',
        ['revoke:Revoke:danger:asks'],
        ['revoke:منسوخ کریں:danger:asks'],
        ['created_at'],
        ['Title', 'Goes to', 'Received', 'Status', 'Open until', 'Made'],
        ['عنوان', 'کہاں جائے گا', 'موصول', 'حالت', 'کب تک کھلا', 'بنایا گیا']],
]);

it('puts the row\'s actions in one ⋮ menu and hides the wide columns at first in the new frame, in English and in Urdu', function (string $which, array $actions, array $urduActions, array $hidden, array $headers, array $urduHeaders) {
    $classicColumns = array_keys(shapeTable($this, $which, false)->getColumns());
    foreach (['en' => [$actions, $headers, 'Actions'], 'ur' => [$urduActions, $urduHeaders, 'کاروائیاں']] as $language => [$list, $words, $menu]) {
        $v2 = shapeTable($this, $which, true, $language);
        [$group, $shown] = shapeActions($v2);
        [$hiddenAtFirst, $inColumns, $labels] = shapeColumns($v2);
        // Filament's own label for the menu, in the console's language; every classic column still there.
        expect($group)->toBe($menu)->and($shown)->toBe($list)
            ->and($hiddenAtFirst)->toBe($hidden)->and($inColumns)->toBe($hidden)
            ->and($labels)->toBe($words)
            ->and(array_keys($v2->getColumns()))->toBe($classicColumns);
    }
})->with('wide tables');

// W-201 classic: the same tables scrolled sideways at 1024-1280 px and cut off their row actions in classic too. Classic
// fits them with the row's actions in one ⋮ menu and the same wide columns shown only on a wide window (from 1280 px),
// with no Columns menu: Filament draws that menu's labels with x-html, which the classic pages never contain.
it('fits classic\'s tables: the row\'s actions in one ⋮ menu, the wide columns only on a wide window, no Columns menu, its English words', function (string $which, array $actions, array $urduActions, array $hidden, array $headers) {
    $classic = shapeTable($this, $which, false);
    [$group, $shown] = shapeActions($classic);
    [$hiddenAtFirst, $inColumns, $labels] = shapeColumns($classic);
    $wideOnly = collect($classic->getColumns())->filter(fn ($c): bool => $c->getVisibleFrom() !== null)->keys()->values()->all();
    expect($group)->toBe('Actions')->and($shown)->toBe($actions)
        ->and($hiddenAtFirst)->toBe([])->and($inColumns)->toBe([])
        ->and($wideOnly)->toBe($hidden)
        ->and($labels)->toBe($headers);
})->with('wide tables');

it('dates the four tables by one rule in the new frame: "24 Nov 2026" in the console\'s language, the time on a second line', function () {
    $get = function (string $page, bool $v2, string $language = 'en'): string {
        webParityDesign($v2);
        $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $this->owner->forceFill(['console_preferences' => $prefs])->save();
        app('auth')->forgetGuards();
        SettingsContext::forget();

        return (string) $this->actingAs($this->owner->fresh(), 'web')->get($this->pages[$page])->assertOk()->getContent();
    };

    // Classic keeps its own date ("Nov 24, 2026"), with the hour on a second line since W-201 (one line was too wide).
    expect($get('request-links', false))->toContain('Nov 24, 2026')->toContain('11:42 AM')->not->toContain('Nov 24, 2026 · 11:42 AM')
        ->and($get('shares', false))->toContain('Nov 24, 2026');
    foreach (['request-links', 'shares', 'recording'] as $page) {
        $en = $get($page, true);
        $ur = $get($page, true, 'ur');
        // The date and, on its own line (a description, readable without hovering), the hour; no tooltip-only time.
        expect($en)->toContain('24 Nov 2026')->toContain('11:42 AM')->not->toContain('Nov 24, 2026')
            ->and($ur)->toContain('24 نومبر 2026')->not->toContain('Nov 24, 2026')->not->toContain('24 Nov 2026');
    }
    // Devices' Signed in, shown from Columns: the same rule, without the hour.
    $devices = shapeTable($this, 'devices', true, 'ur');
    $device = Device::query()->where('user_id', $this->owner->id)->firstOrFail();
    expect($devices->getColumn('created_at')->record($device)->formatState($device->created_at))
        ->toBe($device->created_at->copy()->locale('ur')->translatedFormat('j M Y'))->toContain('نومبر');
});

it('calls the page My links in the new frame, as its sidebar and breadcrumb do, and Shares in classic', function () {
    $get = function (bool $v2): string {
        webParityDesign($v2);
        app('auth')->forgetGuards();
        SettingsContext::forget();

        return (string) $this->actingAs($this->owner->fresh(), 'web')->get($this->pages['shares'])->assertOk()->getContent();
    };
    expect($get(true))->toMatch('~<title>\s*My links~')->toMatch('~<h1[^>]*>\s*My links\s*</h1>~')
        ->and($get(false))->toMatch('~<title>\s*Shares~')->toMatch('~<h1[^>]*>\s*Shares\s*</h1>~');
});

it('offers Rename and Sign out on every device to the owner and an admin in the new frame, and keeps a member out of Devices', function () {
    webParityDesign(true);
    $workspaceId = (int) $this->owner->current_workspace_id;
    $workspace = Workspace::query()->findOrFail($workspaceId);
    $mate = User::query()->where('email', 'sana@qureshi-textiles.example')->firstOrFail();
    $mate->forceFill(['current_workspace_id' => $workspaceId])->save();
    $admin = Fx::user(['name' => 'Omar Admin']);
    app(Workspaces::class)->addMember($workspace, $admin, WorkspaceRole::Admin);
    $admin->forceFill(['current_workspace_id' => $workspaceId])->save();
    $own = Device::query()->where('user_id', $this->owner->id)->firstOrFail();
    $theirs = Fx::device($mate, ['name' => 'Sana laptop', 'workspace_id' => $workspaceId]);

    // The owner and an admin manage every device of the workspace: both rows, both actions in each row's ⋮ menu.
    foreach ([$this->owner, $admin] as $manager) {
        shapeTable($this, 'devices', true, 'en', $manager);
        $page = shapeComponent('devices');
        $page->assertCanSeeTableRecords([$own, $theirs]);
        foreach ([$own, $theirs] as $device) {
            $page->assertTableActionVisible('rename', $device)->assertTableActionVisible('signOut', $device);
        }
    }

    // A member has the small console: Devices is not theirs to open, in either design, and the policy agrees.
    foreach ([true, false] as $v2) {
        webParityDesign($v2);
        app('auth')->forgetGuards();
        SettingsContext::forget();
        $this->actingAs($mate->fresh(), 'web')->get($this->pages['devices'])->assertForbidden();
    }
    expect($mate->fresh()->can('update', $own))->toBeFalse()->and($mate->fresh()->can('delete', $own))->toBeFalse()
        ->and($mate->fresh()->can('update', $theirs))->toBeTrue();
});
