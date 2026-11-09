<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Pages\WorkspaceSettings;
use App\Mail\InvitationMail;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\WorkspaceEraser;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| The role table of docs/API.md "Workspaces, roles and privacy" (since v1.5), invitations, handing over, leaving.
*/

uses(ApiClient::class);

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('app');
});
afterEach(fn () => Fx::cleanupDisks());

/**
 * One workspace with a person of each role, and a member's device.
 *
 * @return array{ws: Workspace, people: array<string, User>}
 */
function everyRole(): array
{
    $owner = Fx::user();
    $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
    $people = ['owner' => $owner];
    foreach ([WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Viewer] as $role) {
        $person = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($ws, $person, $role);
        $people[$role->value] = $person->fresh();
    }

    return ['ws' => $ws, 'people' => $people];
}

/** Each row of the table: ability, then what owner, admin, member and viewer may do. */
const ROLE_MATRIX = [
    'record' => [true, true, true, false],
    'ownContent' => [true, true, true, false],
    'openShared' => [true, true, true, true],
    'saveShared' => [true, true, true, false],
    'invite' => [true, true, false, false],
    'viewUsage' => [true, true, true, false],
    'viewMembersUsage' => [true, true, false, false],
    'manage' => [true, false, false, false],
    'leave' => [false, true, true, true],
];

describe('the policy matrix', function () {
    it('walks every role and every action of the table', function () {
        ['ws' => $ws, 'people' => $people] = everyRole();
        $roles = ['owner', 'admin', 'member', 'viewer'];
        $failures = [];
        foreach (ROLE_MATRIX as $ability => $expected) {
            foreach ($roles as $i => $role) {
                if (Gate::forUser($people[$role])->allows($ability, [$ws]) !== $expected[$i]) {
                    $failures[] = "{$role} {$ability} should be ".($expected[$i] ? 'allowed' : 'refused');
                }
            }
        }
        expect($failures)->toBe([]);
    });

    it('lets the owner handle anyone but themselves, an admin only members and viewers, and nobody else anyone', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $cases = [
            ['owner', 'admin', true], ['owner', 'member', true], ['owner', 'viewer', true], ['owner', 'owner', false],
            ['admin', 'owner', false], ['admin', 'admin', false], ['admin', 'member', true], ['admin', 'viewer', true],
            ['member', 'owner', false], ['member', 'admin', false], ['member', 'viewer', false], ['member', 'member', false],
            ['viewer', 'owner', false], ['viewer', 'member', false], ['viewer', 'viewer', false],
        ];
        foreach ($cases as [$actor, $target, $allowed]) {
            expect(Gate::forUser($p[$actor])->allows('manageMember', [$ws, $p[$target]]))->toBe($allowed, "{$actor} on {$target}");
        }
    });

    it('lets each role give only the roles the table says, and never ownership', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $gives = fn (string $actor): array => collect(WorkspaceRole::cases())
            ->filter(fn (WorkspaceRole $r): bool => Gate::forUser($p[$actor])->allows('assignRole', [$ws, $r]))
            ->map(fn (WorkspaceRole $r): string => $r->value)->values()->all();

        expect($gives('owner'))->toBe(['admin', 'member', 'viewer'])
            ->and($gives('admin'))->toBe(['member', 'viewer'])
            ->and($gives('member'))->toBe([])
            ->and($gives('viewer'))->toBe([]);
    });

    it('lets owners and admins revoke anyone\'s device, everyone their own, nobody across workspaces', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $memberDevice = Device::factory()->create(['user_id' => $p['member']->id, 'workspace_id' => $ws->id]);
        $elsewhere = Fx::device(Fx::user());

        foreach (['owner' => true, 'admin' => true, 'member' => true, 'viewer' => false] as $role => $allowed) {
            expect(Gate::forUser($p[$role])->allows('revokeDevice', [$ws, $memberDevice]))->toBe($allowed, $role)
                ->and(Gate::forUser($p[$role])->allows('revokeDevice', [$ws, $elsewhere]))->toBeFalse();
        }
    });

    it('gives nothing to someone not in the workspace, a super admin included', function () {
        ['ws' => $ws] = everyRole();
        $admin = Fx::superAdmin();

        foreach (array_keys(ROLE_MATRIX) as $ability) {
            expect(Gate::forUser($admin)->allows($ability, [$ws]))->toBeFalse($ability);
        }
    });
});

describe('a viewer\'s device', function () {
    /** @return array{token: string, device: Device, ws: Workspace, viewer: User} */
    function viewerDevice(): array
    {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $device = Device::factory()->create(['user_id' => $p['viewer']->id, 'workspace_id' => $ws->id]);

        return ['token' => Fx::token($device), 'device' => $device, 'ws' => $ws, 'viewer' => $p['viewer']];
    }

    it('gets 403 not_allowed from recording and upload routes, and the app\'s message', function () {
        ['token' => $token, 'device' => $device, 'ws' => $ws] = viewerDevice();
        $session = CaptureSession::factory()->create(['user_id' => $device->user_id, 'workspace_id' => $ws->id, 'device_id' => $device->id]);
        $upload = Fx::pendingRecording($session, str_repeat('b', 2048), 1024);

        foreach ([
            ['POST', '/api/sessions', Fx::sessionPayload()],
            ['POST', "/api/sessions/{$session->id}/recordings", Fx::artifact(['role' => 'channel_1'])],
            ['POST', '/api/stt/token', ['use_case' => 'dictation']],
            ['POST', '/api/live-translate/token', []],
            ['POST', "/api/recordings/{$upload->id}/chunks", []],
            ['POST', "/api/recordings/{$upload->id}/complete", []],
            ['POST', "/api/sessions/{$session->id}/screens", ['at_ms' => 0]],
            ['POST', '/api/request-links', ['title' => 'Send me']],
        ] as [$method, $uri, $body]) {
            $response = $this->api($token)->json($method, $uri, $body);
            expect($response->status())->toBe(403, $uri)->and($response->json('code'))->toBe('not_allowed', $uri)
                ->and($response->json('message'))->toContain('You are a viewer in '.$ws->name);
        }
    });

    it('gets 403 not_allowed from every route that makes or changes recordings and projects, its own old ones included', function () {
        ['token' => $token, 'device' => $device, 'ws' => $ws, 'viewer' => $viewer] = viewerDevice();
        // A member made these before being made a viewer: they stay theirs to open, not to change.
        $project = Client::query()->create(['workspace_id' => $ws->id, 'user_id' => $viewer->id, 'name' => 'Old project']);
        $session = CaptureSession::factory()->create(['user_id' => $viewer->id, 'workspace_id' => $ws->id, 'device_id' => $device->id, 'client_id' => $project->id, 'title' => 'Old call']);
        $transcript = Fx::transcript($session);
        $s = "/api/sessions/{$session->id}";
        $c = "/api/clients/{$project->id}";
        $t = "/api/transcripts/{$transcript->id}";
        $x = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        foreach ([
            ['PATCH', $s, ['title' => 'Renamed']], ['DELETE', $s, []], ['POST', '/api/sessions/bulk', ['ids' => [$session->id], 'action' => 'pin']],
            ['POST', "{$s}/transcripts", []], ['POST', "{$s}/restore", []], ['DELETE', "/api/bin/{$session->id}", []],
            ['POST', "{$s}/markers", ['at_ms' => 0]], ['DELETE', "{$s}/markers/{$x}", []], ['POST', "{$s}/retranscribe", []],
            ['DELETE', "{$s}/screens/{$x}", []], ['PUT', "{$s}/speakers", ['speakers' => []]], ['POST', "{$s}/notes", []],
            ['POST', "{$s}/shares", []], ['POST', "{$s}/send", ['to' => ['someone@example.test']]],
            ['POST', '/api/clients', ['name' => 'New project']], ['PATCH', $c, ['name' => 'Renamed']], ['DELETE', $c, []],
            ['POST', "{$c}/rules", ['kind' => 'title', 'value' => 'Acme']], ['DELETE', "{$c}/rules/1", []],
            ['PUT', "{$c}/brief", ['sections' => ['goal' => 'x']]], ['POST', "{$c}/brief/refresh", []],
            ['POST', "{$c}/memory", ['kind' => 'decision', 'text' => 'x']], ['PATCH', "{$c}/memory/{$x}", ['pinned' => true]], ['DELETE', "{$c}/memory/{$x}", []],
            ['POST', "{$c}/files", []], ['DELETE', "{$c}/files/{$x}", []], ['POST', "{$c}/ask", ['question' => 'What was agreed?']],
            ['POST', "{$c}/shares", []], ['POST', "{$c}/send", ['to' => ['someone@example.test']]],
            ['POST', '/api/vocabulary/keyterms', ['term' => 'Acme', 'client_id' => $project->id]],
            ['POST', '/api/vocabulary/replacements', ['from' => 'acme', 'to' => 'Acme', 'client_id' => $project->id]],
            ['PATCH', $t, ['segments' => []]], ['POST', "{$t}/replace", ['find' => 'a', 'replace' => 'b']], ['POST', "{$t}/views", ['view' => 'roman']],
            ['POST', "{$t}/restore", ['revision' => 1]], ['POST', "{$t}/suggestions/0", ['action' => 'accept']],
        ] as [$method, $uri, $body]) {
            $response = $this->api($token)->json($method, $uri, $body);
            expect($response->status())->toBe(403, "{$method} {$uri}")->and($response->json('code'))->toBe('not_allowed', "{$method} {$uri}")
                ->and($response->json('message'))->toContain('You are a viewer in '.$ws->name);
        }

        expect($session->fresh()->title)->toBe('Old call')
            ->and($session->fresh()->trashed())->toBeFalse()
            ->and(Client::query()->withoutGlobalScopes()->where('user_id', $viewer->id)->pluck('name')->all())->toBe(['Old project']);
        // Opening stays, and taking back a link is never refused (it only narrows who sees a recording).
        $this->api($token)->getJson($s)->assertOk()->assertJsonPath('title', 'Old call');
        $this->api($token)->getJson("{$c}/workspace")->assertOk();
        $this->api($token)->deleteJson("/api/shares/{$x}")->assertNotFound();
    });

    it('reads config, account and what is shared with it', function () {
        ['token' => $token, 'ws' => $ws] = viewerDevice();

        $this->api($token)->getJson('/api/config')->assertOk()->assertJsonPath('workspace.role', 'viewer')->assertJsonPath('workspace.id', $ws->id);
        $this->api($token)->getJson('/api/account')->assertOk()->assertJsonPath('workspace.role', 'viewer');
        $this->api($token)->getJson('/api/shared-with-me')->assertOk();
        $this->api($token)->getJson('/api/library')->assertOk()->assertJsonPath('data', []);
    });

    it('opens what is sent to it but cannot save a copy', function () {
        Mail::fake();
        ['token' => $token, 'viewer' => $viewer] = viewerDevice();
        $sender = Fx::user();
        $session = Fx::session($sender, Fx::device($sender), ['title' => 'For the viewer']);

        $this->api(Fx::token(Device::query()->withoutGlobalScopes()->where('user_id', $sender->id)->firstOrFail()))
            ->postJson("/api/sessions/{$session->id}/send", ['to' => [$viewer->email]])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->api($token)->getJson("/api/sessions/{$session->id}")->assertOk();
        $item = $this->api($token)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');
        $this->api($token)->postJson("/api/shared-with-me/{$item}/save")->assertForbidden()->assertJsonPath('code', 'not_allowed');
    });
});

describe('invitations', function () {
    function openedSignUp(string $mode = SignUp::INVITED): void
    {
        app(SettingsRepository::class)->set('signup.mode', $mode);
    }

    it('emails an account a signed link it accepts once, with the invited role', function () {
        Mail::fake();
        $owner = Fx::user();
        $guest = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);

        $url = app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Viewer);
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m): bool => $m->hasTo($guest->email) && ! $m->signUp && $m->join && $m->url === $url);
        $html = (new InvitationMail($ws->name, $owner->name, $url, signUp: false, expires: 'Nov 13, 2026', join: true))->render();
        expect($html)->toContain('Join '.e($ws->name))->toContain('stays yours');
        expect($ws->roleOf($guest))->toBeNull();

        $this->actingAs($guest, 'web');
        $this->get($url)->assertOk()->assertSee('Join '.$ws->name);
        $this->post($url)->assertRedirect();
        expect($ws->roleOf($guest))->toBe(WorkspaceRole::Viewer)
            ->and($guest->fresh()->current_workspace_id)->toBe($ws->id);

        // One-time: the same link says so now and changes nothing.
        $this->get($url)->assertStatus(410);
        $this->post($url)->assertStatus(410);
    });

    it('refuses a link without its signature, or with a changed one', function () {
        Mail::fake();
        $owner = Fx::user();
        $guest = Fx::user();
        $url = app(Invitations::class)->invite(Workspace::query()->findOrFail($owner->current_workspace_id), $owner, $guest->email, WorkspaceRole::Member);
        $this->actingAs($guest, 'web');

        $this->get(explode('?', $url)[0])->assertForbidden();
        $this->post(explode('?', $url)[0])->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertForbidden();
    });

    it('ends after 7 days', function () {
        Mail::fake();
        $owner = Fx::user();
        $guest = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $url = app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Member);
        $this->actingAs($guest, 'web');

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        // The signature ended with it; the invitation itself says the same to anything that reaches it.
        $this->get($url)->assertForbidden();
        expect(WorkspaceInvitation::query()->where('email', $guest->email)->firstOrFail()->usable())->toBeFalse()
            ->and(app(PlanLimits::class)->seatsUsed($ws->id))->toBe(1)
            ->and($ws->roleOf($guest))->toBeNull();
    });

    it('is only for the invited address: another account is refused and nothing changes', function () {
        Mail::fake();
        $owner = Fx::user();
        $guest = Fx::user();
        $other = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $url = app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Member);
        $this->actingAs($other, 'web');

        $this->get($url)->assertForbidden()->assertSee('another address');
        $this->post($url)->assertStatus(410);
        expect($ws->roleOf($other))->toBeNull();
    });

    it('sends someone signed out to sign in when the address has an account, and to sign up when it has none', function () {
        Mail::fake();
        openedSignUp();
        $owner = Fx::user();
        $guest = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $accountUrl = app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Member);

        $this->get($accountUrl)->assertRedirect(Filament::getPanel('app')->getLoginUrl());
        // An address without an account goes straight to the sign-up page with the invitation.
        $signUpUrl = app(Invitations::class)->invite($ws, $owner, 'brand.new@example.test', WorkspaceRole::Member);
        expect($signUpUrl)->toContain('invite=');
    });

    it('takes the place of an open invitation to the same address, and refuses someone already in the workspace', function () {
        Mail::fake();
        $owner = Fx::user();
        $guest = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);

        $first = app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Member);
        app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Admin);
        expect(WorkspaceInvitation::query()->where('email', $guest->email)->count())->toBe(1);
        $this->actingAs($guest, 'web');
        $this->get($first)->assertStatus(410);

        expect(fn () => app(Invitations::class)->invite($ws, $owner, $owner->email, WorkspaceRole::Member))->toThrow(InvalidArgumentException::class, 'already');
        expect(fn () => app(Invitations::class)->invite($ws, $owner, 'x@example.test', WorkspaceRole::Owner))->toThrow(InvalidArgumentException::class);
    });

    it('answers seat_limit_reached past the plan\'s seats, open invitations counted', function () {
        Mail::fake();
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        Plan::personal()->update(['seats' => 2]);

        app(Invitations::class)->invite($ws, $owner, 'one@example.test', WorkspaceRole::Member);
        try {
            app(Invitations::class)->invite($ws, $owner, 'two@example.test', WorkspaceRole::Member);
            $this->fail('A second invitation passes the two seats.');
        } catch (ApiError $e) {
            expect($e->getStatusCode())->toBe(403)->and($e->apiCode())->toBe('seat_limit_reached');
        }
        expect(WorkspaceInvitation::query()->where('email', 'two@example.test')->exists())->toBeFalse();

        // Inviting the same address again takes its own seat back first.
        app(Invitations::class)->invite($ws, $owner, 'one@example.test', WorkspaceRole::Admin);
    });

    it('checks the seats again when the link is accepted, and a full team keeps the account out', function () {
        Mail::fake();
        $owner = Fx::user();
        $guest = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $url = app(Invitations::class)->invite($ws, $owner, $guest->email, WorkspaceRole::Member);
        Plan::personal()->update(['seats' => 1]);

        $this->actingAs($guest, 'web');
        $this->post($url)->assertForbidden()->assertSee(PlanLimits::SEATS_MESSAGE);
        expect($ws->roleOf($guest))->toBeNull()
            ->and(WorkspaceInvitation::query()->where('email', $guest->email)->firstOrFail()->accepted_at)->toBeNull();
    });

    it('makes the account of a new address with the invitation, and without joining a team that filled up meanwhile', function () {
        Mail::fake();
        openedSignUp();
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Invitations::class)->invite($ws, $owner, 'late@example.test', WorkspaceRole::Member);
        $invitation = WorkspaceInvitation::query()->where('email', 'late@example.test')->firstOrFail();
        Plan::personal()->update(['seats' => 1]);

        $user = app(SignUp::class)->create(['name' => 'Late', 'email' => 'late@example.test', 'password' => 'correct-horse-9'], $invitation);

        expect($ws->roleOf($user))->toBeNull()
            ->and($user->fresh()->workspaces()->count())->toBe(1);
    });

    it('invites from the console with the roles the person may give, and says when no seat is left', function () {
        Mail::fake();
        ['ws' => $ws, 'people' => $p] = everyRole();
        $guest = Fx::user();

        $this->actingAs($p['admin'], 'web');
        Livewire::test(WorkspaceSettings::class)
            ->callAction('addMember', ['email' => 'too-high@example.test', 'role' => 'admin'])
            ->assertHasActionErrors(['role']);
        expect(WorkspaceInvitation::query()->where('email', 'too-high@example.test')->exists())->toBeFalse();

        Livewire::test(WorkspaceSettings::class)
            ->callAction('addMember', ['email' => $guest->email, 'role' => 'viewer'])
            ->assertNotified('Invitation sent to '.$guest->email);

        Plan::personal()->update(['seats' => 1]);
        Livewire::test(WorkspaceSettings::class)
            ->callAction('addMember', ['email' => $guest->email, 'role' => 'member'])
            ->assertNotified(PlanLimits::SEATS_MESSAGE);
        Livewire::test(WorkspaceSettings::class)->assertSee($guest->email);
        expect($ws->roleOf($guest))->toBeNull();
    });

    it('takes an open invitation back, which frees its seat', function () {
        Mail::fake();
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $url = app(Invitations::class)->invite($ws, $owner, 'gone@example.test', WorkspaceRole::Member);
        $id = WorkspaceInvitation::query()->where('email', 'gone@example.test')->value('id');
        $this->actingAs($owner, 'web');

        Livewire::test(WorkspaceSettings::class)->assertSee('gone@example.test')->call('revokeInvitation', $id)->assertNotified();

        expect(WorkspaceInvitation::query()->whereKey($id)->exists())->toBeFalse()->and(app(PlanLimits::class)->seatsUsed($ws->id))->toBe(1);
        expect($url)->toContain('invite=');
    });
});

describe('pending invitations: resend and cancel (since v1.5, Phase 1C)', function () {
    it('sends a pending invitation again with a new link, the old one no longer works', function () {
        Mail::fake();
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        $first = app(Invitations::class)->invite($ws, $owner, 'again@example.test', WorkspaceRole::Member);
        $old = WorkspaceInvitation::query()->where('email', 'again@example.test')->sole();
        $this->actingAs($owner, 'web');

        Livewire::test(WorkspaceSettings::class)->assertSee('Pending invitations')->assertSee('Resend')->assertSee('Cancel')
            ->call('resendInvitation', $old->id)->assertNotified('Invitation sent again to again@example.test');

        $new = WorkspaceInvitation::query()->where('email', 'again@example.test')->sole();
        expect($new->id)->not->toBe($old->id)->and($new->role)->toBe(WorkspaceRole::Member)
            ->and(app(Invitations::class)->find(Str::after($first, 'invite=')))->toBeNull();
        Mail::assertQueued(InvitationMail::class, 2);
    });

    it('counts resending against the same hourly limit as Invite, and limits cancelling too', function () {
        Mail::fake();
        $owner = Fx::user();
        $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Invitations::class)->invite($ws, $owner, 'limit@example.test', WorkspaceRole::Member);
        $this->actingAs($owner, 'web');
        foreach (range(1, WorkspaceSettings::ADDS_PER_HOUR) as $_) {
            RateLimiter::hit('add-member:'.$owner->id, 3600);
        }
        $id = WorkspaceInvitation::query()->where('email', 'limit@example.test')->value('id');

        Livewire::test(WorkspaceSettings::class)->call('resendInvitation', $id)->assertNotified('Too many invitations sent for now');
        Mail::assertQueued(InvitationMail::class, 1);

        foreach (range(1, WorkspaceSettings::CANCELS_PER_HOUR) as $_) {
            RateLimiter::hit('cancel-invitation:'.$owner->id, 3600);
        }
        Livewire::test(WorkspaceSettings::class)->call('revokeInvitation', $id)->assertNotified('Too many invitations cancelled for now');
        expect(WorkspaceInvitation::query()->whereKey($id)->exists())->toBeTrue();
    });

    it('lets only those who may invite resend or cancel, and only in their workspace', function () {
        Mail::fake();
        ['ws' => $ws, 'people' => $p] = everyRole();
        app(Invitations::class)->invite($ws, $p['owner'], 'kept@example.test', WorkspaceRole::Member);
        $id = WorkspaceInvitation::query()->where('email', 'kept@example.test')->value('id');
        $stranger = Fx::user();
        $this->actingAs($stranger, 'web');
        Livewire::test(WorkspaceSettings::class)->call('resendInvitation', $id)->call('revokeInvitation', $id);
        $this->actingAs($p['member'], 'web');
        Livewire::test(WorkspaceSettings::class)->call('resendInvitation', $id)->call('revokeInvitation', $id);

        expect(WorkspaceInvitation::query()->whereKey($id)->exists())->toBeTrue();
        Mail::assertQueued(InvitationMail::class, 1);
    });
});

describe('who can sign up, in plain words (since v1.5, Phase 1C)', function () {
    it('says what On and Off mean for family and friends', function () {
        expect(SignUp::SETTING_LABELS)->toHaveKeys([SignUp::ANYONE, SignUp::NOBODY, SignUp::INVITED])
            ->and(SignUp::SETTING_LABELS[SignUp::ANYONE])->toContain('family and friends')
            ->and(SignUp::SETTING_HELP)->toContain('own free account')->toContain('Nobody sees anyone else\'s recordings unless they are shared')
            ->toContain('Off: there is no sign-up page');
    });
});

describe('handing over and leaving', function () {
    it('makes the picked member the owner and the old owner an admin', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $this->actingAs($p['owner'], 'web');

        Livewire::test(WorkspaceSettings::class)->callAction('transfer', ['user_id' => $p['member']->id])->assertNotified();

        expect($ws->roleOf($p['member']))->toBe(WorkspaceRole::Owner)
            ->and($ws->roleOf($p['owner']))->toBe(WorkspaceRole::Admin);
    });

    it('refuses a viewer, a stranger or oneself as the new owner, and anyone but the owner handing over', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $workspaces = app(Workspaces::class);

        foreach ([$p['viewer'], Fx::user(), $p['owner']] as $to) {
            expect(fn () => $workspaces->transfer($ws, $p['owner'], $to))->toThrow(InvalidArgumentException::class);
        }
        expect(fn () => $workspaces->transfer($ws, $p['admin'], $p['member']))->toThrow(InvalidArgumentException::class, 'Only the owner');
        $this->actingAs($p['admin'], 'web');
        Livewire::test(WorkspaceSettings::class)->assertActionHidden('transfer')->assertActionHidden('rename')->assertActionHidden('deleteWorkspace');
    });

    it('lets a member leave: devices signed out, their recordings stay theirs and hidden', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $own = app(Workspaces::class)->createFor($p['member'], 'Own');
        $device = Device::factory()->create(['user_id' => $p['member']->id, 'workspace_id' => $ws->id]);
        $session = CaptureSession::factory()->create(['user_id' => $p['member']->id, 'workspace_id' => $ws->id, 'device_id' => $device->id]);
        $this->actingAs($p['member']->fresh(), 'web');

        Livewire::test(WorkspaceSettings::class)->callAction('leave')->assertRedirect();

        expect($ws->roleOf($p['member']))->toBeNull()
            ->and($p['member']->fresh()->current_workspace_id)->toBe($own->id)
            ->and(unscoped(fn () => Device::query()->find($device->id)))->toBeNull()
            ->and(unscoped(fn () => CaptureSession::query()->find($session->id)?->user_id))->toBe($p['member']->id);
    });

    it('asks the owner to hand over before leaving, and refuses leaving the only workspace', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $workspaces = app(Workspaces::class);

        expect(fn () => $workspaces->leave($ws, $p['owner']))->toThrow(InvalidArgumentException::class, 'Hand the workspace over')
            ->and(fn () => $workspaces->leave($ws, $p['member']))->toThrow(InvalidArgumentException::class, 'only workspace');
        $this->actingAs($p['owner'], 'web');
        Livewire::test(WorkspaceSettings::class)->assertActionHidden('leave');
    });

    it('lets an admin remove a member, not the owner or another admin', function () {
        ['ws' => $ws, 'people' => $p] = everyRole();
        $other = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($ws, $other, WorkspaceRole::Admin);
        $this->actingAs($p['admin'], 'web');

        $page = Livewire::test(WorkspaceSettings::class)
            ->assertActionHidden(TestAction::make('remove')->table($p['owner']))
            ->assertActionHidden(TestAction::make('remove')->table($other->fresh()))
            ->assertActionHidden(TestAction::make('changeRole')->table($p['owner']))
            ->assertActionVisible(TestAction::make('remove')->table($p['viewer']));
        $page->callAction(TestAction::make('remove')->table($p['member']));

        expect($ws->roleOf($p['member']))->toBeNull();
    });

    it('moves a person between workspaces with the switcher, and only their own', function () {
        $user = Fx::user();
        $second = app(Workspaces::class)->createFor($user, 'Second place');
        $stranger = Workspace::query()->findOrFail(Fx::user()->current_workspace_id);
        $this->actingAs($user, 'web');

        $this->get('/app')->assertOk()->assertSee('Second place');
        $this->post(route('admin.workspaces.switch', $second->id))->assertRedirect();
        expect($user->fresh()->current_workspace_id)->toBe($second->id);

        $this->post(route('admin.workspaces.switch', $stranger->id))->assertNotFound();
        expect($user->fresh()->current_workspace_id)->toBe($second->id);
    });

    it('hides the switcher from someone in one workspace', function () {
        $this->actingAs(Fx::user(), 'web');

        $this->get('/app')->assertOk()->assertDontSee('Switch workspace', false);
    });
});

describe('deleting a workspace', function () {
    it('erases everything in it, for its owner alone, once nobody else is in it', function () {
        $owner = Fx::user();
        $first = Workspace::query()->findOrFail($owner->current_workspace_id);
        $team = app(Workspaces::class)->createFor($owner, 'Team');
        app(Workspaces::class)->switchTo($owner, $team);
        $owner->refresh();
        $member = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($team, $member);
        $device = Fx::device($owner);
        $session = Fx::session($owner, $device, ['title' => 'Team call']);
        $recording = Fx::readyRecording($session, 'team audio');
        $path = (string) $recording->stored_path;
        $this->actingAs($owner, 'web');

        Livewire::test(WorkspaceSettings::class)->callAction('deleteWorkspace')->assertNotified('Remove everyone else first: deleting a workspace deletes everything in it.');
        expect(Workspace::query()->find($team->id))->not->toBeNull();

        app(Workspaces::class)->removeMember($team, $member);
        Livewire::test(WorkspaceSettings::class)->callAction('deleteWorkspace')->assertRedirect();

        expect(Workspace::query()->find($team->id))->toBeNull()
            ->and($owner->fresh()->current_workspace_id)->toBe($first->id)
            ->and(unscoped(fn () => CaptureSession::withTrashed()->find($session->id)))->toBeNull()
            ->and(unscoped(fn () => Device::query()->find($device->id)))->toBeNull()
            ->and(Storage::disk('recordings')->exists($path))->toBeFalse();
    });

    it('refuses deleting the only workspace', function () {
        $owner = Fx::user();

        expect(fn () => app(WorkspaceEraser::class)->erase(Workspace::query()->findOrFail($owner->current_workspace_id), $owner))
            ->toThrow(InvalidArgumentException::class, 'only workspace');
    });
});

describe('sign-up', function () {
    it('gives a new account its own workspace on the Free plan', function () {
        app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);

        $user = app(SignUp::class)->create(['name' => 'New', 'email' => 'new@example.test', 'password' => 'correct-horse-9']);

        $plan = Workspace::query()->findOrFail($user->current_workspace_id)->plan;
        // Since v1.6 the Free plan of the default plans: the free features, 2 devices, 1 GB, 30 days, one seat.
        expect($plan->key)->toBe(Plan::FREE)
            ->and($plan->monthly_minutes)->toBe(0)
            ->and($plan->storage_bytes)->toBe(1024 ** 3)
            ->and($plan->max_devices)->toBe(2)
            ->and($plan->retention_days)->toBe(30)
            ->and($plan->seats)->toBe(1)
            ->and($plan->aiCredits())->toBe(0.0)
            ->and(array_filter($plan->featureMap()))->toBe([]);
    });
});
