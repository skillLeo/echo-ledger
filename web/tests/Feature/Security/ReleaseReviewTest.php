<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Pages\AccountBeingDeleted;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Mail\AccountMail;
use App\Mail\DeletionScheduledMail;
use App\Mail\ExportReadyMail;
use App\Mail\InvitationMail;
use App\Mail\LoginAlertMail;
use App\Mail\ResetPasswordMail;
use App\Mail\ShareReceivedMail;
use App\Mail\TranscriptReadyMail;
use App\Mail\VerifyEmailMail;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ConnectedApp;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\DeviceSignInCode;
use App\Models\Plan;
use App\Models\Screen;
use App\Models\Setting;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\AppServiceProvider;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

uses(ApiClient::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Fx::fakeRecordingsDisk();
});

/*
| The findings of the v1.5 release's security review, each with what guards it (C1, M1 … m7, H1). Every test here failed
| before its fix.
*/

afterEach(function () {
    // TrustHosts sets this on Symfony's Request for the whole process, and it would outlive the test.
    Request::setTrustedHosts([]);
    Fx::cleanupDisks();
});

/** A team of [$owner], its current workspace, and a device of [$person] in it (the owner when none is named). */
function reviewTeam(User $owner, ?User $person = null, WorkspaceRole $role = WorkspaceRole::Member): array
{
    $team = app(Workspaces::class)->createFor($owner, 'Team', Plan::personal());
    $owner->forceFill(['current_workspace_id' => $team->id])->save();
    if ($person !== null) {
        app(Workspaces::class)->addMember($team, $person, $role);
    }
    $device = Device::factory()->create(['user_id' => ($person ?? $owner)->id, 'workspace_id' => $team->id]);

    return [$team, $device];
}

/** A recording of the person in the workspace, past any scope: what the device API and the console read. */
function reviewSession(User $person, int $workspaceId, array $attributes = []): CaptureSession
{
    return CaptureSession::acrossPeople()->create([
        'workspace_id' => $workspaceId, 'user_id' => $person->id, 'client_ref' => (string) Str::uuid(), 'source_device' => 'phone', 'source_app' => 'phone_call',
        'title' => 'A call', 'started_at' => now(), 'status' => 'completed', ...$attributes,
    ]);
}

// ---- C1: the Host header and the links this server emails ----------------------------------------------------------

/**
 * Laravel's TrustHosts and our root URL both skip themselves in local and in tests (`runningUnitTests()` reads the
 * environment), so as far as the two can tell this test is production: the real middleware and the real boot code run.
 */
function asProduction(): void
{
    app()['env'] = 'production';
    AppServiceProvider::useAppUrl(app());
}

it('C1 trusts exactly the host of APP_URL, on every request', function () {
    expect(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue()
        ->and(app(TrustHosts::class)->hosts())->toBe(['^echoledger\.test$']);
});

it('C1 refuses another Host, a subdomain and a name that only starts with ours, and serves its own', function (string $host, int $status) {
    asProduction();

    $this->get("http://{$host}/admin/password-reset/request")->assertStatus($status);
})->with([
    'someone else\'s' => ['evil.test', 400],
    'a subdomain' => ['app.echoledger.test', 400],
    'ours as a prefix' => ['echoledger.test.evil.test', 400],
    'ours' => ['echoledger.test', 200],
]);

it('C1 puts APP_URL in the reset email whatever Host the request named', function () {
    asProduction();
    Mail::fake();
    $user = Fx::user(['email' => 'target@corp.test']);
    // A request the host check did not stop: the same code the reset page calls, while the request says evil.test.
    Request::setTrustedHosts([]);
    $request = Request::create('http://evil.test/admin/password-reset/request');
    app()->instance('request', $request);
    URL::setRequest($request);

    $user->sendPasswordResetNotification('a-reset-token');

    Mail::assertQueued(ResetPasswordMail::class, fn (ResetPasswordMail $mail): bool => str_starts_with($mail->url, 'https://echoledger.test/'));
});

it('C1 keeps the request\'s address in local, where a phone reaches the laptop by its IP', function () {
    app()['env'] = 'local';
    AppServiceProvider::useAppUrl(app());
    $request = Request::create('http://192.168.1.20:8000/admin');
    app()->instance('request', $request);
    URL::setRequest($request);

    expect(url('/admin/login'))->toBe('http://192.168.1.20:8000/admin/login');
});

// ---- M5: browsers end with "Sign out everywhere" on the documented VPS too -----------------------------------------

it('M5 keeps sessions in the database in the VPS guide: only those rows can be ended', function () {
    $guide = (string) file_get_contents(dirname(base_path()).'/docs/DEPLOY_VPS.md');

    expect($guide)->toContain("\nSESSION_DRIVER=database\n")->not->toContain('SESSION_DRIVER=redis');
});

// ---- M7: a session the password change ended, and "Forgot password?" ------------------------------------------------

/** Every console route outside the panel a browser session reaches: [method, uri, body]. */
function sessionRoutes(User $user): array
{
    $device = Fx::device($user);
    $session = Fx::session($user, $device);
    $transcript = Fx::transcript($session, 'Secret words.');
    $recording = Fx::readyRecording($session);
    $screen = Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => 0, 'width' => 1, 'height' => 1,
        'trigger' => 'manual', 'sha256' => str_repeat('a', 64), 'mime' => 'image/png', 'bytes' => 1, 'image_path' => 'screens/x.png', 'thumb_path' => 'screens/x-thumb.png']);

    return [
        'the player' => ['GET', "/admin-media/recordings/{$recording->id}", []],
        'a screen' => ['GET', "/admin-media/screens/{$screen->id}/image", []],
        'an export' => ['GET', "/admin-export/transcripts/{$transcript->id}/txt", []],
        'a meeting pack' => ['GET', "/admin-export/sessions/{$session->id}/pack/html", []],
        'an upload' => ['POST', '/admin-upload/recordings', ['name' => 'a.ogg', 'size' => 10, 'sha256' => str_repeat('a', 64)]],
        'switching workspace' => ['POST', "/admin-workspaces/{$user->current_workspace_id}/switch", []],
    ];
}

it('M7 ends a session on every console route outside the panel once the password changed, and only then', function () {
    $user = Fx::user(['password' => 'old-password-1234']);
    $routes = sessionRoutes($user);
    $session = fn (): array => [Auth::guard('web')->getName() => $user->id, 'password_hash_web' => $user->fresh()->getAuthPassword()];
    $before = $session();
    $login = Filament::getPanel('admin')->getLoginUrl();

    foreach ($routes as $name => [$method, $uri, $body]) {
        $alive = $this->withSession($before)->call($method, $uri, $body);
        expect($alive->isRedirect($login))->toBeFalse("{$name} with a live session");
        app('auth')->forgetGuards();
        $this->flushSession();
    }

    $user->forceFill(['password' => 'new-password-5678'])->save();

    foreach ($routes as $name => [$method, $uri, $body]) {
        $this->withSession($before)->call($method, $uri, $body)->assertRedirect($login);
        expect(Auth::guard('web')->check())->toBeFalse($name);
        app('auth')->forgetGuards();
        $this->flushSession();
    }
});

it('M7 lets no ended session link Google, accept an invitation or schedule the account\'s deletion', function () {
    $user = Fx::user(['email' => 'victim@corp.test', 'password' => 'old-password-1234']);
    $stolen = [Auth::guard('web')->getName() => $user->id, 'password_hash_web' => $user->getAuthPassword()];
    $user->forceFill(['password' => 'new-password-5678'])->save();
    fakeGoogle('attacker-sub', 'attacker@gmail.test', 'A');
    $login = Filament::getPanel('admin')->getLoginUrl();

    $this->withSession($stolen)->get('/auth/google/redirect?intent=link')->assertRedirect($login);
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->withSession([...$stolen, 'google.intent' => 'link'])->get('/auth/google/callback?code=a&state=b')->assertRedirect($login);
    expect($user->fresh()->google_id)->toBeNull();
    app('auth')->forgetGuards();
    $this->flushSession();

    $this->withSession($stolen)->post('/account/delete', ['password' => 'new-password-5678', 'confirm' => 'DELETE'])->assertRedirect($login);
    expect($user->fresh()->deletes_at)->toBeNull();
    app('auth')->forgetGuards();
    $this->flushSession();

    Mail::fake();
    $team = app(Workspaces::class)->createFor(Fx::user(), 'Their team', Plan::personal());
    app(Invitations::class)->invite($team, Fx::user(), 'victim@corp.test', WorkspaceRole::Member);
    $link = '';
    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$link): bool {
        $link = $mail->url;

        return true;
    });
    $this->withSession($stolen)->post($link)->assertRedirect($login);
    expect($team->roleOf($user->fresh()))->toBeNull();
});

it('M7 signs an account out everywhere when "Forgot password?" sets a new password, through Filament\'s own page', function () {
    Mail::fake();
    $user = Fx::user(['email' => 'victim@corp.test', 'password' => 'old-password-1234']);
    $device = Fx::device($user);
    $token = Fx::token($device);
    ConnectedApp::query()->create(['user_id' => $user->id, 'client_id' => (string) Str::uuid()]);
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert(['id' => 'an-attackers-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
    app(PairingService::class)->createCode($user);

    $reset = Password::broker(Filament::getAuthPasswordBroker())->createToken($user);
    Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $reset])
        ->fillForm(['password' => 'taken-back-password-1', 'passwordConfirmation' => 'taken-back-password-1'])
        ->call('resetPassword');

    expect(Hash::check('taken-back-password-1', (string) $user->fresh()->password))->toBeTrue()
        ->and(Device::query()->withoutGlobalScopes()->whereKey($device->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(ConnectedApp::query()->connected()->where('user_id', $user->id)->count())->toBe(0)
        ->and(DevicePairingCode::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(0);
    config(['session.driver' => 'array']);
    $this->api($token)->getJson('/api/account')->assertUnauthorized();
});

it('M7 leaves devices and apps signed in when the password is changed on the profile', function () {
    $user = Fx::user(['password' => 'old-password-1234']);
    $device = Fx::device($user);
    $token = Fx::token($device);
    ConnectedApp::query()->create(['user_id' => $user->id, 'client_id' => (string) Str::uuid()]);

    $this->actingAs($user, 'web');
    Livewire::test(EditProfile::class)
        ->fillForm(['password' => 'a-new-password-1234', 'passwordConfirmation' => 'a-new-password-1234', 'currentPassword' => 'old-password-1234'])
        ->call('save')->assertHasNoFormErrors();

    expect(Hash::check('a-new-password-1234', (string) $user->fresh()->password))->toBeTrue()
        ->and(ConnectedApp::query()->connected()->where('user_id', $user->id)->count())->toBe(1);
    $this->api($token)->getJson('/api/account')->assertOk();
});

// ---- m5: an account being deleted stops at "Keep my account" on routes outside the panel too ------------------------

it('m5 leads an account being deleted to "Keep my account" from every console route outside the panel', function () {
    $user = Fx::user();
    $routes = sessionRoutes($user);
    $user->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(7)])->save();
    $this->actingAs($user->fresh(), 'web');

    foreach ($routes as $name => [$method, $uri, $body]) {
        $response = $this->call($method, $uri, $body);
        $method === 'GET' ? $response->assertRedirect(AccountBeingDeleted::getUrl()) : $response->assertForbidden();
    }
    expect(CaptureSession::acrossPeople()->where('user_id', $user->id)->where('source_device', 'web')->count())->toBe(0);
});

// ---- M1: the server's engine routing is the operator's ---------------------------------------------------------------

function engineTrial(User $person): array
{
    $device = Fx::device($person);
    $session = Fx::session($person, $device);
    Fx::readyRecording($session);
    $run = $session->allTranscripts()->create(['kind' => TranscriptKind::Trial, 'provider' => 'soniox', 'status' => TranscriptStatus::Completed, 'language' => 'ur', 'text' => 'x', 'segments' => []]);

    return [$session, $run];
}

it('M1 refuses a workspace owner "Make default" and does not offer it', function () {
    $owner = Fx::user();
    [$session, $run] = engineTrial($owner);
    $before = Setting::query()->where('key', 'routing.final')->value('value');
    $this->actingAs($owner, 'web');

    $modal = Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->mountAction('engine_results');
    expect(implode('', array_map('strval', (array) ($modal->effects['partials'] ?? []))))->toContain('Use this version')->not->toContain('Make default for');
    Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->call('pickEngine', $run->id)->assertForbidden();

    expect(Setting::query()->where('key', 'routing.final')->value('value'))->toBe($before);
});

it('M1 still lets the super admin make an engine the default', function () {
    $admin = Fx::superAdmin();
    [$session, $run] = engineTrial($admin);
    $before = app(SettingsRepository::class)->get('routing.final');
    $this->actingAs($admin, 'web');

    $modal = Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->mountAction('engine_results');
    expect(implode('', array_map('strval', (array) ($modal->effects['partials'] ?? []))))->toContain('Make default for');
    Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->call('pickEngine', $run->id)->assertOk();

    expect((new SettingsRepository)->get('routing.final'))->not->toBe($before);
});

// ---- M6: a Google account links only to a confirmed address ----------------------------------------------------------

it('M6 refuses to link Google while the email is unconfirmed, so a squatted sign-up keeps no way in', function () {
    Mail::fake();
    app(SettingsRepository::class)->set('signup.mode', 'anyone');
    $squat = app(SignUp::class)->create(['name' => 'x', 'email' => 'victim@corp.test', 'password' => 'attacker-pass-123456']);
    fakeGoogle('attacker-google-sub', 'attacker@gmail.test', 'Attacker');
    $this->actingAs($squat, 'web');

    $this->get('/auth/google/redirect?intent=link')->assertStatus(422)->assertSee('Confirm your email first');
    $this->withSession(['google.intent' => 'link'])->get('/auth/google/callback?code=a&state=b')->assertStatus(422);

    expect($squat->fresh()->google_id)->toBeNull();
});

it('M6 still links Google to a confirmed account', function () {
    $user = Fx::user();
    fakeGoogle('own-google-sub', 'own@gmail.test', 'Own');
    $this->actingAs($user, 'web');

    $this->get('/auth/google/redirect?intent=link')->assertRedirect();
    $this->get('/auth/google/callback?code=a&state=b')->assertRedirect();

    expect($user->fresh()->google_id)->toBe('own-google-sub');
});

// ---- M8: the console login obeys the lock on an email ----------------------------------------------------------------

function consoleLogin(string $email, string $password): void
{
    Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
}

it('M8 refuses the right password while the email is locked, in the page\'s words, and takes it once the lock ends', function () {
    Fx::user(['email' => 'target@corp.test', 'password' => 'correct-password-123']);
    // Five failures in five different minutes: Filament's own limit, per visitor, never sees them together.
    foreach (range(1, 5) as $i) {
        $this->travel(61)->seconds();
        consoleLogin('target@corp.test', 'wrong');
    }
    expect(app(SignInGuard::class)->lockedFor('target@corp.test'))->toBeGreaterThan(0);

    $this->travel(61)->seconds();
    Livewire::test(Login::class)->fillForm(['email' => 'TARGET@corp.test', 'password' => 'correct-password-123'])->call('authenticate')
        ->assertNotified('Too many login attempts');
    expect(Auth::guard('web')->check())->toBeFalse();

    $this->travel(16)->minutes();
    consoleLogin('target@corp.test', 'correct-password-123');
    expect(Auth::guard('web')->check())->toBeTrue();
});

it('M8 clears the failures after a good sign-in', function () {
    Fx::user(['email' => 'target@corp.test', 'password' => 'correct-password-123']);
    foreach (range(1, 4) as $i) {
        $this->travel(61)->seconds();
        consoleLogin('target@corp.test', 'wrong');
    }
    $this->travel(61)->seconds();
    consoleLogin('target@corp.test', 'correct-password-123');
    expect(Auth::guard('web')->check())->toBeTrue();
    Auth::guard('web')->logout();

    // Four more, and it is still not locked: the count started again.
    foreach (range(1, 4) as $i) {
        $this->travel(61)->seconds();
        consoleLogin('target@corp.test', 'wrong');
    }
    expect(app(SignInGuard::class)->lockedFor('target@corp.test'))->toBe(0);
});

// ---- m1: a removed person's pairing and sign-in codes end with them ----------------------------------------------------

it('m1 drops the person\'s codes when they are removed from the workspace', function () {
    $owner = Fx::user();
    $member = Fx::user();
    [$team] = reviewTeam($owner, $member);
    $member->forceFill(['current_workspace_id' => $team->id])->save();
    $code = app(PairingService::class)->createCode($member);
    DeviceSignInCode::query()->withoutGlobalScopes()->create(['user_id' => $member->id, 'workspace_id' => $team->id, 'code_hash' => hash('sha256', 'x'), 'code_challenge' => 'y', 'name' => 'n', 'platform' => 'android', 'expires_at' => now()->addMinutes(5)]);
    $own = app(Workspaces::class)->createFor($member, 'Own', Plan::personal());
    $kept = DevicePairingCode::query()->withoutGlobalScopes()->create(['user_id' => $member->id, 'workspace_id' => $own->id, 'code_hash' => hash('sha256', 'kept'), 'expires_at' => now()->addMinutes(5)]);

    app(Workspaces::class)->removeMember($team, $member);

    expect(DevicePairingCode::query()->withoutGlobalScopes()->where('workspace_id', $team->id)->count())->toBe(0)
        ->and(DeviceSignInCode::query()->withoutGlobalScopes()->where('workspace_id', $team->id)->count())->toBe(0)
        ->and(DevicePairingCode::query()->withoutGlobalScopes()->whereKey($kept->id)->exists())->toBeTrue();
    $this->api()->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'back', 'platform' => 'android'])->assertStatus(422);
});

it('m1 refuses a code whose person is no longer in its workspace, even if the code is still there', function () {
    $owner = Fx::user();
    $member = Fx::user();
    [$team] = reviewTeam($owner, $member);
    $member->forceFill(['current_workspace_id' => $team->id])->save();
    $code = app(PairingService::class)->createCode($member);

    $team->members()->detach($member->id); // the code outlived the membership: a race with the removal
    app()->forgetInstance(Workspaces::class);

    $this->api()->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'back', 'platform' => 'android'])->assertStatus(422);
    expect(Device::query()->withoutGlobalScopes()->where('workspace_id', $team->id)->where('user_id', $member->id)->where('name', 'back')->exists())->toBeFalse();
});

// ---- m3: what the shared list says about the sender ---------------------------------------------------------------------

it('m3 names neither the sender\'s device nor project in the shared list', function () {
    $sender = Fx::user();
    $recipient = Fx::user();
    [$team, $recipientDevice] = reviewTeam($sender, $recipient);
    $device = Device::factory()->create(['user_id' => $sender->id, 'workspace_id' => $team->id, 'name' => 'Ana private iPhone']);
    $project = Client::acrossPeople()->create(['workspace_id' => $team->id, 'user_id' => $sender->id, 'name' => 'Private project']);
    $session = reviewSession($sender, $team->id, ['device_id' => $device->id, 'client_id' => $project->id, 'filed_by' => 'user']);
    app(Teammates::class)->send($sender, $session, [$recipient->email], null);

    $json = $this->api(Fx::token($recipientDevice))->getJson('/api/library?folder=shared')->assertOk();

    expect($json->json('data.0.id'))->toBe($session->id)
        ->and($json->json('data.0.device'))->toBeNull()
        ->and($json->json('data.0.client'))->toBeNull()
        ->and($json->json('data.0.filed_by'))->toBeNull()
        ->and($json->getContent())->not->toContain('Ana private iPhone')->not->toContain('Private project');
});

// ---- m7: names in the owner's toasts ------------------------------------------------------------------------------------

it('m7 shows a member\'s name in the workspace page\'s toasts as text', function () {
    $owner = Fx::user();
    $name = '<a href="https://evil.test/x">Session expired, sign in</a>';
    $member = Fx::user(['name' => $name]);
    reviewTeam($owner, $member);
    $this->actingAs($owner, 'web');

    Livewire::test(WorkspaceSettings::class)->callAction(TestAction::make('changeRole')->table($member), data: ['role' => WorkspaceRole::Viewer->value])
        ->assertNotified(e($name).' is now viewer');

    Livewire::test(WorkspaceSettings::class)->callAction(TestAction::make('remove')->table($member))->assertNotified(e($name).' removed');
    expect(app(Workspaces::class)->roleOf($member->fresh(), (int) $owner->current_workspace_id))->toBeNull();
});

// ---- H1: links in queued emails ------------------------------------------------------------------------------------------

it('H1 keeps every account email encrypted while it waits in the queue', function () {
    config(['queue.default' => 'database']);

    Mail::to('someone@corp.test')->queue(new ResetPasswordMail('https://echoledger.test/reset?signature=a-secret-link'));

    $payload = (string) DB::table('jobs')->latest('id')->value('payload');
    expect($payload)->not->toBe('')->not->toContain('a-secret-link');
    foreach ([DeletionScheduledMail::class, ExportReadyMail::class, InvitationMail::class, LoginAlertMail::class, ResetPasswordMail::class, ShareReceivedMail::class, TranscriptReadyMail::class, VerifyEmailMail::class] as $mail) {
        expect(is_subclass_of($mail, AccountMail::class))->toBeTrue($mail)->and(is_subclass_of($mail, ShouldBeEncrypted::class))->toBeTrue($mail);
    }
});

// ---- M2: a usage report counts only for streaming the device was granted --------------------------------------------

function usageBody(int $ms, ?string $id = null, string $useCase = 'dictation', string $provider = 'assemblyai'): array
{
    return ['report_id' => $id ?? 'rep-'.bin2hex(random_bytes(6)), 'use_case' => $useCase, 'provider' => $provider, 'streamed_ms' => $ms, 'channels' => 2];
}

function fakeProviders(): void
{
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'x');
    app(CredentialVault::class)->put('soniox', 'api_key', 'x');
    Http::fake(fn () => Http::response(['token' => 't', 'api_key' => 'k', 'expires_at' => now()->addMinutes(5)->toIso8601String()], 200));
}

it('M2 stores a report no grant stands behind with 0 and still answers 201, then 200 for its repeat, so the device stops', function () {
    $device = Fx::device();
    $token = Fx::token($device);

    $this->api($token)->postJson('/api/usage', usageBody(86_400_000, 'made-up-1'))->assertCreated()
        ->assertJsonPath('report.streamed_ms', 0)->assertJsonPath('report.billed_ms', 0)->assertJsonPath('report.cost_usd', 0);
    $this->api($token)->postJson('/api/usage', usageBody(86_400_000, 'made-up-1'))->assertOk()
        ->assertJsonPath('report.duplicate', true)->assertJsonPath('report.streamed_ms', 0);
});

it('M2 stops one tenant from ending transcription for every tenant with made-up reports', function () {
    app(SettingsRepository::class)->set('budget.monthly_minutes', 5000);
    fakeProviders();
    $badToken = Fx::token(Fx::device());
    $goodToken = Fx::token(Fx::device());

    foreach ([1, 2] as $i) {
        $this->api($badToken)->postJson('/api/usage', usageBody(86_400_000))->assertCreated();
    }

    $this->api($goodToken)->postJson('/api/stt/token', ['use_case' => 'dictation'])->assertOk();
});

it('M2 lets a device report the time since its stt/token, with 5 minutes\' slack, and clamps what goes past it', function () {
    fakeProviders();
    $device = Fx::device();
    $token = Fx::token($device);
    $this->freezeTime();
    $this->api($token)->postJson('/api/stt/token', ['use_case' => 'dictation'])->assertOk();

    $this->travel(1)->hours();
    $this->api($token)->postJson('/api/usage', usageBody(3_000_000))->assertCreated()->assertJsonPath('report.streamed_ms', 3_000_000);
    // 65 minutes in all, 50 of them taken: 15 left.
    $this->api($token)->postJson('/api/usage', usageBody(3_000_000))->assertCreated()->assertJsonPath('report.streamed_ms', 900_000)
        ->assertJsonPath('report.billed_ms', 1_800_000);
    $this->api($token)->postJson('/api/usage', usageBody(1000))->assertCreated()->assertJsonPath('report.streamed_ms', 0);

    // More time passes, more can be reported.
    $this->travel(10)->minutes();
    $this->api($token)->postJson('/api/usage', usageBody(3_000_000))->assertCreated()->assertJsonPath('report.streamed_ms', 600_000);
});

it('M2 notes live-translate/token as a grant too, and only for the device that got it', function () {
    fakeProviders();
    $device = Fx::device();
    $other = Fx::device($device->user);
    $this->api(Fx::token($device))->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en'])->assertOk();

    $this->api(Fx::token($device))->postJson('/api/usage', usageBody(60_000, null, 'game_translate', 'soniox'))->assertCreated()->assertJsonPath('report.streamed_ms', 60_000);
    $this->api(Fx::token($other))->postJson('/api/usage', usageBody(60_000, null, 'game_translate', 'soniox'))->assertCreated()->assertJsonPath('report.streamed_ms', 0);
});

it('M2 takes nothing from what is left for a repeated report', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    grantStreaming($device, 0);

    $report = usageBody(240_000);
    $this->api($token)->postJson('/api/usage', $report)->assertCreated()->assertJsonPath('report.streamed_ms', 240_000);
    $this->api($token)->postJson('/api/usage', $report)->assertOk()->assertJsonPath('report.streamed_ms', 240_000);

    $this->api($token)->postJson('/api/usage', usageBody(60_000))->assertCreated()->assertJsonPath('report.streamed_ms', 60_000);
});

it('M2 counts from the first grant of the last 48 hours, and what was reported since it', function () {
    $this->freezeTime();
    $device = Fx::device();
    $token = Fx::token($device);

    // Long gone: as good as no grant at all.
    grantStreaming($device, 49);
    $this->api($token)->postJson('/api/usage', usageBody(60_000))->assertCreated()->assertJsonPath('report.streamed_ms', 0);

    grantStreaming($device, 30);
    grantStreaming($device, 1);
    $all = 30 * 3_600_000 + 300_000;
    $this->api($token)->postJson('/api/usage', usageBody(86_400_000))->assertCreated()->assertJsonPath('report.streamed_ms', 86_400_000);
    $this->api($token)->postJson('/api/usage', usageBody(86_400_000))->assertCreated()->assertJsonPath('report.streamed_ms', $all - 86_400_000);

    // The first grant has left the 48 hours: the next one counts now, with the reports made since it.
    $this->travel(19)->hours();
    $this->api($token)->postJson('/api/usage', usageBody(3_600_000))->assertCreated()->assertJsonPath('report.streamed_ms', 0);
    $this->travel(30)->hours();
    $this->api($token)->postJson('/api/usage', usageBody(1000))->assertCreated()->assertJsonPath('report.streamed_ms', 0);
});

it('M2 answers 403 to a viewer\'s report, so a viewer cannot use up a team\'s minutes', function () {
    $owner = Fx::user();
    $plan = Plan::personal();
    $plan->update(['monthly_minutes' => 100]);
    $team = app(Workspaces::class)->createFor($owner, 'Team', $plan);
    $viewer = Fx::user();
    app(Workspaces::class)->addMember($team, $viewer, WorkspaceRole::Viewer);
    $device = Device::factory()->create(['user_id' => $viewer->id, 'workspace_id' => $team->id]);
    grantStreaming($device);

    $this->api(Fx::token($device))->postJson('/api/usage', usageBody(86_400_000))->assertForbidden()->assertJsonPath('code', 'not_allowed');

    expect(app(PlanLimits::class)->minutesReached($team->id))->toBeFalse()
        ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->where('workspace_id', $team->id)->count())->toBe(0);
});

// ---- m6: continuing over Live Translate's budget --------------------------------------------------------------------

function liveTranslateSpent(int $workspaceId, User $person, float $usd): void
{
    UsageLedgerEntry::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'user_id' => $person->id, 'source' => 'device', 'report_id' => 'lt-'.bin2hex(random_bytes(6)), 'use_case' => 'game_translate',
        'provider' => 'soniox', 'duration_ms' => 1, 'channels' => 1, 'billed_ms' => 1, 'unit_price_usd' => 1, 'cost_usd' => $usd, 'billable' => true, 'occurred_at' => now(),
    ]);
}

it('m6 lets an owner, an admin and the super admin continue over budget, and refuses a member whatever the flag says', function (string $who, int $status) {
    app(SettingsRepository::class)->set('live_translate.monthly_usd', 1);
    fakeProviders();
    $owner = Fx::user();
    $person = $who === 'super admin' ? Fx::superAdmin() : ($who === 'owner' ? $owner : Fx::user());
    $role = match ($who) {
        'admin' => WorkspaceRole::Admin,
        default => WorkspaceRole::Member,
    };
    [$team, $device] = reviewTeam($owner, $who === 'owner' ? null : $person, $role);
    liveTranslateSpent($team->id, $owner, 1.0);
    $token = Fx::token($device);

    $this->api($token)->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en'])->assertForbidden()->assertJsonPath('code', 'budget_exceeded');
    $flagged = $this->api($token)->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en', 'continue_over_budget' => true])->assertStatus($status);
    if ($status === 403) {
        $flagged->assertJsonPath('code', 'budget_exceeded');
        expect($flagged->json('message'))->toContain('Ask an owner');
    }
})->with([
    'owner' => ['owner', 200],
    'admin' => ['admin', 200],
    'super admin' => ['super admin', 200],
    'member' => ['member', 403],
]);

it('m6 stops everyone at twice the budget, flag or not', function () {
    app(SettingsRepository::class)->set('live_translate.monthly_usd', 1);
    fakeProviders();
    $owner = Fx::user();
    [$team, $device] = reviewTeam($owner);
    $token = Fx::token($device);
    liveTranslateSpent($team->id, $owner, 1.99);
    $this->api($token)->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en', 'continue_over_budget' => true])->assertOk();

    liveTranslateSpent($team->id, $owner, 0.01);
    $this->api($token)->postJson('/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en', 'continue_over_budget' => true])
        ->assertForbidden()->assertJsonPath('code', 'budget_exceeded');
});
