<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Pages\ApiCredentials;
use App\Filament\Pages\Settings;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\PairingService;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

beforeEach(fn () => Fx::fakeRecordingsDisk());
afterEach(fn () => Fx::cleanupDisks());

/**
 * One workspace with an owner and a member, each with a device and a recording.
 *
 * @return array{ws: Workspace, owner: User, member: User, ownerDevice: Device, memberDevice: Device, ownerSession: CaptureSession, memberSession: CaptureSession}
 */
function team(): array
{
    $owner = Fx::user();
    $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
    $member = withoutWorkspace(Fx::user());
    app(Workspaces::class)->addMember($ws, $member);
    $member->refresh();
    $ownerDevice = Fx::device($owner);
    $memberDevice = Device::factory()->create(['user_id' => $member->id, 'workspace_id' => $ws->id]);

    return [
        'ws' => $ws, 'owner' => $owner, 'member' => $member, 'ownerDevice' => $ownerDevice, 'memberDevice' => $memberDevice,
        'ownerSession' => Fx::session($owner, $ownerDevice, ['title' => 'Owner call']),
        'memberSession' => CaptureSession::factory()->create(['user_id' => $member->id, 'workspace_id' => $ws->id, 'device_id' => $memberDevice->id, 'title' => 'Member call']),
    ];
}

function billedMinutes(int $workspaceId, int $minutes): void
{
    UsageLedgerEntry::query()->create([
        'workspace_id' => $workspaceId, 'user_id' => Workspace::query()->findOrFail($workspaceId)->members()->value('users.id'),
        'source' => 'server', 'report_id' => 'final/'.Str::ulid().'/a1', 'use_case' => 'final', 'provider' => 'assemblyai',
        'duration_ms' => $minutes * 60000, 'channels' => 1, 'billed_ms' => $minutes * 60000, 'unit_price_usd' => 0.15,
        'cost_usd' => 0.01, 'billable' => true, 'occurred_at' => now(),
    ]);
}

describe('roles', function () {
    it('keeps each library private since v1.5: a member sees only their recordings, the owner only theirs', function () {
        $t = team();

        $member = collect($this->api(Fx::token($t['memberDevice']))->getJson('/api/library?limit=100')->assertOk()->json('data'))->pluck('id');
        $this->app['auth']->forgetGuards();
        $owner = collect($this->api(Fx::token($t['ownerDevice']))->getJson('/api/library?limit=100')->assertOk()->json('data'))->pluck('id');

        expect($member->all())->toBe([$t['memberSession']->id])->and($owner->all())->toBe([$t['ownerSession']->id]);
    });

    it('lets a member delete only their own recordings through the API; anyone else\'s is a 404', function () {
        $t = team();
        $token = Fx::token($t['memberDevice']);

        $this->api($token)->deleteJson("/api/sessions/{$t['ownerSession']->id}")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        $this->api($token)->deleteJson("/api/sessions/{$t['memberSession']->id}")->assertNoContent();

        expect(unscoped(fn () => CaptureSession::query()->find($t['ownerSession']->id)))->not->toBeNull()
            ->and(unscoped(fn () => CaptureSession::query()->find($t['memberSession']->id)))->toBeNull();
    });

    it('does not let the owner delete a member\'s recording since v1.5: it is a 404 to them', function () {
        $t = team();

        $this->api(Fx::token($t['ownerDevice']))->deleteJson("/api/sessions/{$t['memberSession']->id}")->assertNotFound()->assertJsonPath('code', 'session_not_found');

        expect(unscoped(fn () => CaptureSession::query()->find($t['memberSession']->id)))->not->toBeNull();
    });

    it('keeps a member from restoring or erasing the owner\'s recording in the bin: a 404', function () {
        $t = team();
        $t['ownerSession']->delete();
        $token = Fx::token($t['memberDevice']);

        $this->api($token)->postJson("/api/sessions/{$t['ownerSession']->id}/restore")->assertNotFound()->assertJsonPath('code', 'session_not_found');
        $this->api($token)->deleteJson("/api/bin/{$t['ownerSession']->id}")->assertNotFound()->assertJsonPath('code', 'session_not_found');

        expect(unscoped(fn () => CaptureSession::onlyTrashed()->find($t['ownerSession']->id)?->media_erased_at))->toBeNull();
    });

    it('skips the owner\'s recordings when a member bulk-deletes', function () {
        $t = team();

        $this->api(Fx::token($t['memberDevice']))
            ->postJson('/api/sessions/bulk', ['ids' => [$t['ownerSession']->id, $t['memberSession']->id], 'action' => 'delete'])
            ->assertOk()->assertJsonPath('changed', 1);

        expect(unscoped(fn () => CaptureSession::query()->find($t['ownerSession']->id)))->not->toBeNull();
    });

    it('shows a member in the console only their own recordings and devices', function () {
        $t = team();
        $this->actingAs($t['member'], 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$t['memberSession']])
            ->assertCanNotSeeTableRecords([$t['ownerSession']]);
        Livewire::test(ListDevices::class)
            ->assertCanSeeTableRecords([$t['memberDevice']])
            ->assertCanNotSeeTableRecords([$t['ownerDevice']])
            ->assertActionVisible(TestAction::make(DeleteAction::class)->table($t['memberDevice']));
    });

    it('shows the owner every device of the workspace to revoke, but only their own recordings', function () {
        $t = team();
        $this->actingAs($t['owner'], 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$t['ownerSession']])
            ->assertCanNotSeeTableRecords([$t['memberSession']]);
        Livewire::test(ListDevices::class)
            ->assertCanSeeTableRecords([$t['ownerDevice'], $t['memberDevice']])
            ->assertActionVisible(TestAction::make(DeleteAction::class)->table($t['memberDevice']));
    });

    it('lets only a super admin\'s device change the shared vocabulary; anyone may add to their own project, nobody to someone else\'s', function () {
        $t = team();
        $client = Fx::client($t['owner'], 'Team client');
        $own = Fx::client($t['member'], 'My client');
        $token = Fx::token($t['memberDevice']);

        $this->api($token)->postJson('/api/vocabulary/keyterms', ['term' => 'Kubernetes'])->assertForbidden()->assertJsonPath('code', 'not_allowed');
        $this->api($token)->postJson('/api/vocabulary/replacements', ['from' => 'kubes', 'to' => 'Kubernetes'])->assertForbidden();
        $this->api($token)->postJson('/api/vocabulary/keyterms', ['term' => 'Kubernetes', 'client_id' => $client->id])->assertNotFound();
        $this->api($token)->postJson('/api/vocabulary/keyterms', ['term' => 'Kubernetes', 'client_id' => $own->id])->assertCreated();
    });

    it('keeps server pages for super admins: a member gets 403 on Settings and API credentials', function () {
        $t = team();
        $this->actingAs($t['member'], 'web');

        $this->get(Settings::getUrl())->assertForbidden();
        $this->get(ApiCredentials::getUrl())->assertForbidden();
        $this->get('/admin')->assertOk();
    });
});

describe('memberships', function () {
    it('revokes a removed member\'s devices there and moves them back to a workspace they own', function () {
        $t = team();
        $own = app(Workspaces::class)->createFor($t['member'], 'Side project');
        $token = Fx::token($t['memberDevice']);

        app(Workspaces::class)->removeMember($t['ws'], $t['member']);

        expect(unscoped(fn () => Device::query()->find($t['memberDevice']->id)))->toBeNull()
            ->and($t['member']->fresh()->current_workspace_id)->toBe($own->id)
            ->and(unscoped(fn () => CaptureSession::query()->find($t['memberSession']->id)))->not->toBeNull();
        $this->app['auth']->forgetGuards();
        $this->api($token)->getJson('/api/config')->assertUnauthorized();
    });

    it('never leaves a workspace without an owner', function () {
        $t = team();
        $workspaces = app(Workspaces::class);

        expect(fn () => $workspaces->removeMember($t['ws'], $t['owner']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $workspaces->setRole($t['ws'], $t['owner'], WorkspaceRole::Member))->toThrow(InvalidArgumentException::class);

        $workspaces->setRole($t['ws'], $t['member'], WorkspaceRole::Owner);
        $workspaces->setRole($t['ws'], $t['owner'], WorkspaceRole::Member);
        expect($workspaces->roleOf($t['owner'], $t['ws']->id))->toBe(WorkspaceRole::Member);
    });

    it('repairs a stale current workspace on the next console request', function () {
        $t = team();
        $own = app(Workspaces::class)->createFor($t['member'], 'Side project');
        $t['ws']->members()->detach($t['member']->id); // removed behind the service's back
        $this->actingAs($t['member']->fresh(), 'web');

        $this->get('/admin')->assertOk();
        expect($t['member']->fresh()->current_workspace_id)->toBe($own->id);
    });

    it('refuses the console to someone whose only membership was removed', function () {
        $t = team();
        $t['ws']->members()->detach($t['member']->id);
        $this->actingAs($t['member']->fresh(), 'web');

        $this->get('/admin')->assertForbidden();
        $this->get(route('admin.recordings.stream', Fx::readyRecording($t['ownerSession'])))->assertForbidden();
    });
});

describe('plan limits', function () {
    it('keeps existing workspaces on the Personal plan: every feature, no limits', function () {
        $plan = Workspace::query()->findOrFail(Fx::user()->current_workspace_id)->plan;

        expect($plan->key)->toBe(Plan::PERSONAL)
            ->and($plan->monthly_minutes)->toBeNull()
            ->and($plan->storage_bytes)->toBeNull()
            ->and($plan->max_devices)->toBeNull()
            ->and($plan->retention_days)->toBeNull()
            ->and($plan->seats)->toBeNull()
            ->and($plan->aiCredits())->toBeNull()
            ->and($plan->featureMap())->toBe(['live_translate' => true, 'screen_moments' => true, 'ai_notes' => true, 'connector' => true]);
    });

    it('refuses streaming tokens once the workspace used its monthly minutes, and only for that workspace', function () {
        $t = team();
        $other = Fx::device(Fx::user());
        Plan::personal()->update(['monthly_minutes' => 10]);
        billedMinutes($t['ws']->id, 10);

        $this->api(Fx::token($t['memberDevice']))->postJson('/api/stt/token', ['use_case' => 'dictation'])
            ->assertForbidden()->assertJson(['code' => 'budget_exceeded', 'message' => PlanLimits::MINUTES_MESSAGE]);
        $this->app['auth']->forgetGuards();
        expect($this->api(Fx::token($other))->postJson('/api/stt/token', ['use_case' => 'dictation'])->json('code'))->not->toBe('budget_exceeded');
    });

    it('refuses a new upload that would pass the storage limit', function () {
        $t = team();
        Plan::personal()->update(['storage_bytes' => 1000]);

        $this->api(Fx::token($t['ownerDevice']))->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['size_bytes' => 4096])]]))
            ->assertForbidden()->assertJson(['code' => 'storage_limit_reached', 'message' => PlanLimits::STORAGE_MESSAGE]);
    });

    it('refuses pairing past the device limit, in the console and at redeem time', function () {
        $t = team(); // two devices
        $code = app(PairingService::class)->createCode($t['owner']);
        Plan::personal()->update(['max_devices' => 2]);

        expect(fn () => app(PairingService::class)->createCode($t['owner']))->toThrow(ApiError::class, PlanLimits::DEVICES_MESSAGE);
        $this->api()->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Third', 'platform' => 'android'])
            ->assertForbidden()->assertJson(['code' => 'device_limit_reached', 'message' => PlanLimits::DEVICES_MESSAGE]);

        $this->actingAs($t['owner'], 'web');
        Filament::setCurrentPanel('admin');
        Livewire::test(ListDevices::class)->mountAction('pair')->assertActionDataSet(['pairing' => ['error' => PlanLimits::DEVICES_MESSAGE]]);
    });

    it('alerts every owner of the workspace at 80 % and 100 % of the plan minutes, once each, and nobody else', function () {
        $t = team();
        $outsider = Fx::user();
        Plan::personal()->update(['monthly_minutes' => 10]);
        $kinds = fn (User $u): array => $u->fresh()->notifications->map(fn ($n) => ActivityFeed::kindOf($n))->filter(fn (?string $k): bool => str_starts_with((string) $k, 'budget'))->values()->all();

        billedMinutes($t['ws']->id, 8);
        billedMinutes($t['ws']->id, 1);
        expect($kinds($t['owner']))->toBe(['budget_warn'])->and($kinds($t['member']))->toBe([]);

        billedMinutes($t['ws']->id, 1);
        billedMinutes($t['ws']->id, 1);
        expect($kinds($t['owner']))->toEqualCanonicalizing(['budget_warn', 'budget_reached'])
            ->and($kinds($t['member']))->toBe([])
            ->and($kinds($outsider))->toBe([]);
    });
    it('never blocks when a limit is empty', function () {
        $t = team();
        Plan::personal()->update(['monthly_minutes' => null, 'storage_bytes' => null, 'max_devices' => null]);
        billedMinutes($t['ws']->id, 100000);
        $limits = app(PlanLimits::class);

        $limits->assertMinutes($t['ws']->id);
        $limits->assertStorage($t['ws']->id, PHP_INT_MAX);
        $limits->assertDevices($t['ws']->id);
        expect($limits->summary($t['ws']->id)['minutes'])->toBe(['used' => 100000.0, 'limit' => null]);
    });
});
