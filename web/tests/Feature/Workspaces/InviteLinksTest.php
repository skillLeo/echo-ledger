<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\WorkspaceSettings;
use App\Mail\EmailCodeMail;
use App\Mail\InvitationMail;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInviteLink;
use App\Services\Accounts\InviteLinks;
use App\Services\Accounts\SignUp;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * Inviting people (v1.8 P6.3): email invitations for 1, 7 or 30 days, invite links for members or viewers (shown
 * once, revocable, a number of people or any), the seats, "Join Acme on EchoLedger", then the apps.
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
});

/** An owner of a team with a device, and the team. */
function inviteTeam(): array
{
    $owner = Fx::user(['name' => 'Omar Owner']);
    $team = Workspace::query()->findOrFail($owner->current_workspace_id);
    $team->forceFill(['name' => 'Acme'])->save();

    return [$owner, $team, Fx::device($owner, ['workspace_id' => $team->id])];
}

/** The token of an invite link's address. */
function linkToken(string $url): string
{
    return (string) basename((string) parse_url($url, PHP_URL_PATH));
}

describe('the device API', function () {
    it('makes an invite link shown once, lists it with the seats and invitations, and revokes it', function () {
        [, $team, $device] = inviteTeam();
        $api = $this->api(Fx::token($device));

        $made = $api->postJson('/api/workspace/invite-links', ['role' => 'viewer', 'days' => 30, 'max_uses' => 3])->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        expect($made->json('url'))->toStartWith(url('/join/'))
            ->and($made->json('link'))->toMatchArray(['role' => 'viewer', 'max_uses' => 3, 'uses' => 0, 'open' => true, 'revoked_at' => null]);
        $api->postJson('/api/workspace/invitations', ['email' => 'Sara@Example.test', 'role' => 'member', 'days' => 1])->assertCreated()
            ->assertJsonPath('invitation.email', 'sara@example.test')->assertJsonPath('invitation.role', 'member');
        expect(WorkspaceInvitation::query()->sole()->expires_at->diffInHours(now(), true))->toBeLessThanOrEqual(24.0);
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $m): bool => $m->hasTo('sara@example.test'));

        $list = $api->getJson('/api/workspace/invitations')->assertOk();
        expect($list->json('seats.used'))->toBe(2)->and($list->json('invitations.0.email'))->toBe('sara@example.test')
            ->and($list->json('links.0.id'))->toBe($made->json('link.id'))
            // The address is never shown again.
            ->and((string) $list->getContent())->not->toContain(linkToken($made->json('url')));

        $api->deleteJson('/api/workspace/invite-links/'.$made->json('link.id'))->assertNoContent();
        $this->get($made->json('url'))->assertStatus(410)->assertSee('This invite link is no longer open');
        expect($api->getJson('/api/workspace/invitations')->json('links'))->toBe([]);
        $api->deleteJson('/api/workspace/invitations/'.$list->json('invitations.0.id'))->assertNoContent();
        expect(WorkspaceInvitation::query()->count())->toBe(0);
    });

    it('gives links to members and viewers only, lets only owners and admins invite, and admins give no admin', function () {
        [$owner, $team, $device] = inviteTeam();
        $this->api(Fx::token($device))->postJson('/api/workspace/invite-links', ['role' => 'admin'])->assertStatus(422);

        $admin = Fx::user();
        app(Workspaces::class)->addMember($team, $admin, WorkspaceRole::Admin);
        $adminApi = $this->api(Fx::token(Fx::device($admin, ['workspace_id' => $team->id])));
        $adminApi->postJson('/api/workspace/invitations', ['email' => 'boss@example.test', 'role' => 'admin'])->assertForbidden()->assertJsonPath('code', 'not_allowed');
        $adminApi->postJson('/api/workspace/invite-links', ['role' => 'member'])->assertCreated();

        $member = Fx::user();
        app(Workspaces::class)->addMember($team, $member, WorkspaceRole::Member);
        $memberApi = $this->api(Fx::token(Fx::device($member, ['workspace_id' => $team->id])));
        foreach ([['GET', '/api/workspace/invitations', []], ['POST', '/api/workspace/invite-links', ['role' => 'viewer']], ['POST', '/api/workspace/invitations', ['email' => 'x@example.test', 'role' => 'viewer']]] as [$method, $uri, $body]) {
            $memberApi->json($method, $uri, $body)->assertForbidden()->assertJsonPath('code', 'not_allowed');
        }
        expect(WorkspaceInviteLink::query()->count())->toBe(1);
    });
});

describe('joining with a link', function () {
    it('shows "Join Acme on EchoLedger" with who invited and as what, joins a signed-in account, then the apps', function () {
        app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
        [$owner, $team] = inviteTeam();
        ['url' => $url, 'link' => $link] = app(InviteLinks::class)->create($team, $owner, WorkspaceRole::Member, 7, 2);

        $this->get($url)->assertOk()->assertSee('Join Acme on')->assertSee('Omar Owner')->assertSee('as member')
            ->assertSee('Create your account', false)->assertSee('I have an account: sign in');

        $guest = Fx::user(['email' => 'guest@example.test']);
        $this->actingAs($guest, 'web');
        $this->get($url)->assertOk()->assertSee('Join Acme');
        $this->post(route('join.accept', ['token' => linkToken($url)]))->assertRedirect(GetTheApps::getUrl(panel: 'app'));
        expect($team->roleOf($guest))->toBe(WorkspaceRole::Member)->and($guest->fresh()->current_workspace_id)->toBe($team->id)
            ->and($link->fresh()->uses)->toBe(1);
        // Again: already in, nothing more used.
        $this->get($url)->assertOk()->assertSee('You are in Acme already');
        $this->post(route('join.accept', ['token' => linkToken($url)]))->assertRedirect();
        expect($link->fresh()->uses)->toBe(1);

        // Its last place, then closed.
        $this->actingAs(Fx::user(), 'web');
        $this->post(route('join.accept', ['token' => linkToken($url)]))->assertRedirect();
        $this->actingAs(Fx::user(), 'web');
        $this->post(route('join.accept', ['token' => linkToken($url)]))->assertStatus(410);
        expect($link->fresh()->uses)->toBe(2);
    });

    it('refuses an ended link, and a join once the plan\'s seats are taken', function () {
        [$owner, $team] = inviteTeam();
        ['url' => $ended] = app(InviteLinks::class)->create($team, $owner, WorkspaceRole::Viewer, 1, null);
        $this->travel(25)->hours();
        $this->get($ended)->assertStatus(410);

        ['url' => $url] = app(InviteLinks::class)->create($team, $owner, WorkspaceRole::Viewer, 7, null);
        Plan::query()->whereKey($team->plan_id)->update(['seats' => 1]);
        $this->actingAs($joiner = Fx::user(), 'web');
        $this->post(route('join.accept', ['token' => linkToken($url)]))->assertForbidden()->assertSee('This team is full');
        expect($team->roleOf($joiner))->toBeNull();
    });

    it('lets a new person sign up with the link while sign-up is by invitation, joins the team, then the apps', function () {
        app(SettingsRepository::class)->set('signup.mode', SignUp::INVITED);
        [$owner, $team] = inviteTeam();
        ['url' => $url, 'link' => $link] = app(InviteLinks::class)->create($team, $owner, WorkspaceRole::Viewer, 7, null);
        $this->get($url)->assertOk()->assertSee('/app/register?join='.linkToken($url), false);

        $page = Livewire::withQueryParams(['join' => linkToken($url)])->test(Register::class)->assertDontSee('Sign-up on this server is by invitation');
        $page->fillForm(['email' => 'new.person@example.test'])->call('authenticate')->assertHasNoFormErrors();
        $code = null;
        Mail::assertQueued(EmailCodeMail::class, function (EmailCodeMail $mail) use (&$code): bool {
            $code = $mail->hasTo('new.person@example.test') ? $mail->code : $code;

            return true;
        });
        $page->fillForm(['code' => $code])->call('authenticate')->assertSet('step', Login::STEP_PROFILE)
            ->fillForm(['name' => 'New Person', 'accept_terms' => true])->call('authenticate')->assertRedirect(GetTheApps::getUrl(panel: 'app'));

        $person = User::query()->where('email', 'new.person@example.test')->sole();
        expect($team->roleOf($person))->toBe(WorkspaceRole::Viewer)->and($link->fresh()->uses)->toBe(1);
        // Without the link, sign-up stays by invitation.
        auth('web')->logout();
        Livewire::withQueryParams([])->test(Register::class)->assertSee('Sign-up on this server is by invitation');
    });
});

describe('the console', function () {
    it('invites by email for the days picked, shows the seats, makes a link shown once and revokes it', function () {
        [$owner, $team] = inviteTeam();
        Plan::query()->whereKey($team->plan_id)->update(['seats' => 10]);
        app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
        $this->actingAs($owner, 'web');

        Livewire::test(WorkspaceSettings::class)->callAction('addMember', ['email' => 'mina@example.test', 'role' => 'viewer', 'days' => '30'])->assertHasNoActionErrors();
        expect((int) round(WorkspaceInvitation::query()->sole()->expires_at->diffInDays(now(), true)))->toBe(30);

        $page = Livewire::test(WorkspaceSettings::class)->assertSee('2 of 10 seats taken')
            ->callAction('inviteLink', ['role' => 'member', 'days' => '7', 'max_uses' => '5'])->assertHasNoActionErrors()
            ->assertActionMounted('inviteLinkShown');
        $url = (string) $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->getState()['url'];
        expect($url)->toStartWith(url('/join/'))->and($page->get('newInviteLink'))->toBeNull();
        $link = WorkspaceInviteLink::query()->sole();
        Livewire::test(WorkspaceSettings::class)->assertSee('0 of 5 joined')->call('revokeLink', $link->id)->assertNotified('Invite link revoked');
        expect($link->fresh()->revoked_at)->not->toBeNull();
    });
});
