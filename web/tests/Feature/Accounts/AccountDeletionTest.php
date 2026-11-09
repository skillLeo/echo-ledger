<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\AccountBeingDeleted;
use App\Mail\DeletionScheduledMail;
use App\Models\AccountExport;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Device;
use App\Models\Recording;
use App\Models\RequestLink;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountDeletion;
use App\Services\Accounts\GoogleSignIn;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Screens\ScreenStore;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/account_helpers.php';
require_once __DIR__.'/../Api/Support/screen_helpers.php';

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
});

describe('GET /api/account', function () {
    it('describes the device\'s person and the workspace it acts in', function () {
        $user = Fx::user(['name' => 'Farah Khan', 'email' => 'farah@example.test']);
        $user->forceFill(['google_id' => 'google-sub-1'])->save();
        accountsEnrolTotp($user);
        [$device, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->getJson('/api/account')->assertOk()->assertExactJson([
            'name' => 'Farah Khan', 'email' => 'farah@example.test', 'email_verified' => true, 'two_factor' => true, 'google' => true,
            'created_at' => $user->created_at->toAtomString(),
            'workspace' => ['id' => $device->workspace_id, 'name' => 'Personal', 'role' => 'owner'],
            'deletes_at' => null,
            // Since v1.6.
            'super_admin' => false,
            'workspaces' => [['id' => $device->workspace_id, 'name' => 'Personal', 'role' => 'owner', 'current' => true]],
        ]);
    });
});

describe('DELETE /api/account', function () {
    it('schedules the deletion for 7 days after the password, signs out everywhere and emails the date', function () {
        $user = Fx::user(['email' => 'leaving@example.test']);
        [, $token] = $this->enroll($user);
        Device::factory()->create(['user_id' => $user->id]);

        $this->asToken($token)->deleteJson('/api/account', ['password' => 'wrong-password-1'])->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        expect($user->fresh()->deletes_at)->toBeNull();

        $response = $this->asToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertStatus(202);
        $deletesAt = $user->fresh()->deletes_at;
        expect($response->json())->toBe(['deletes_at' => $deletesAt->toAtomString()])
            ->and(abs($deletesAt->diffInHours(now()->addDays(7))))->toBeLessThan(1)
            ->and(PersonalAccessToken::findToken($token))->toBeNull()
            // Since v1.6 signed-out rows stay listed (no token); the account's erasure removes them.
            ->and(unscoped(fn () => Device::query()->where('user_id', $user->id)->whereNull('signed_out_at')->count()))->toBe(0)
            ->and(AuditEvent::query()->where('event', 'account_deletion_requested')->where('user_id', $user->id)->exists())->toBeTrue();
        Mail::assertQueued(DeletionScheduledMail::class, fn (DeletionScheduledMail $mail): bool => $mail->hasTo('leaving@example.test'));
    });

    it('takes a two-step code instead of the password', function () {
        $user = Fx::user();
        [$secret] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->deleteJson('/api/account', ['otp' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->deleteJson('/api/account', ['otp' => accountsTotp($user, $secret)])->assertStatus(202);
        $this->asToken($token)->deleteJson('/api/account', [])->assertUnauthorized();
    });

    it('needs the password or a code, and locks after five wrong ones (429)', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->deleteJson('/api/account', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        foreach (range(1, 5) as $i) {
            $this->asToken($token)->deleteJson('/api/account', ['password' => 'wrong-'.$i])->assertStatus(422);
        }
        $this->asToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        expect($user->fresh()->deletes_at)->toBeNull();
    });

    it('refuses the only owner of a workspace with other members (409 owner_of_team), not a co-owner', function () {
        $owner = Fx::user();
        $team = Workspace::query()->findOrFail($owner->current_workspace_id);
        $member = Fx::user();
        app(Workspaces::class)->addMember($team, $member);
        [, $token] = $this->enroll($owner);

        $this->asToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertStatus(409)->assertJsonPath('code', 'owner_of_team');

        app(Workspaces::class)->setRole($team, $member, WorkspaceRole::Owner);
        $this->asToken($token)->deleteJson('/api/account', ['password' => 'password'])->assertStatus(202);
    });
});

describe('the 7 days', function () {
    it('shows only "Keep my account" on the console, which cancels the deletion', function () {
        $user = Fx::user();
        $user->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(7)])->save();
        $this->actingAs($user, 'web');

        $this->get('/app')->assertRedirect(AccountBeingDeleted::getUrl());
        $this->get('/app/profile')->assertRedirect(AccountBeingDeleted::getUrl());
        $this->get('/app/sessions')->assertRedirect(AccountBeingDeleted::getUrl());
        $this->get(AccountBeingDeleted::getUrl())->assertOk()->assertSee('Keep my account');

        Livewire::test(AccountBeingDeleted::class)->callAction(TestAction::make('keepAccount')->schemaComponent('deletion-actions', schema: 'content'))
            ->assertRedirect();
        expect($user->fresh()->deletes_at)->toBeNull()
            ->and(AuditEvent::query()->where('event', 'account_deletion_cancelled')->exists())->toBeTrue();
        $this->actingAs($user->fresh(), 'web')->get('/app')->assertOk();
    });
});

describe('/account/delete', function () {
    it('explains and sends a guest to the console sign-in, then back', function () {
        $this->get('/account/delete')->assertOk()->assertSee('Delete your account')->assertSee('Sign in to continue');
        $this->get('/account/delete?sign_in=1')->assertRedirect(route('filament.app.auth.login'));
        expect(session('url.intended'))->toBe(url('/account/delete?sign_in=1'));
    });

    it('confirms with the password and the box, then says when', function () {
        $user = Fx::user(['email' => 'phone.user@example.test']);
        $this->actingAs($user, 'web');
        $this->get('/account/delete')->assertOk()->assertSee('phone.user@example.test')->assertSee('name="password"', false)->assertSee('viewport', false);

        $this->post('/account/delete', ['password' => 'password'])->assertSessionHasErrors('understood');
        $this->post('/account/delete', ['password' => 'nope-nope-1', 'understood' => '1'])->assertStatus(422)->assertSee('That password is wrong');
        expect($user->fresh()->deletes_at)->toBeNull();

        $this->post('/account/delete', ['password' => 'password', 'understood' => '1'])->assertOk()->assertSee('Your account will be deleted');
        expect($user->fresh()->deletes_at)->not->toBeNull();
        $this->assertGuest('web');
    });

    it('lets an account made with Google confirm with that Google account, which it has no password for', function () {
        fakeGoogle(id: 'google-sub-9', email: 'made.with.google@example.test');
        $user = Fx::user(['email' => 'made.with.google@example.test', 'password' => Str::password(40)]);
        $user->forceFill(['google_id' => 'google-sub-9'])->save();
        $this->actingAs($user, 'web');

        $page = $this->get('/account/delete')->assertOk()->assertSee('Confirm with Google')->assertSee('name="password"', false);
        expect($page->getContent())->toContain(e(route('auth.google.redirect', ['intent' => 'confirm'])));

        // Nothing is confirmed without Google or the password.
        $this->post('/account/delete', ['understood' => '1'])->assertStatus(422);
        expect($user->fresh()->deletes_at)->toBeNull();

        $this->get('/auth/google/redirect?intent=confirm')->assertRedirect();
        $this->get('/auth/google/callback')->assertRedirect(route('account.delete'));
        $this->get('/account/delete')->assertOk()->assertSee('Confirmed with your Google account')->assertDontSee('name="password"', false);
        expect(AuditEvent::query()->where('event', 'google_confirmed')->where('user_id', $user->id)->exists())->toBeTrue();

        $this->post('/account/delete', ['understood' => '1'])->assertOk()->assertSee('Your account will be deleted');
        expect($user->fresh()->deletes_at)->not->toBeNull();
        $this->assertGuest('web');
    });

    it('counts only the linked Google account, for a few minutes, and never instead of a two-step code', function () {
        $user = Fx::user(['email' => 'linked@example.test']);
        $user->forceFill(['google_id' => 'google-sub-linked'])->save();
        $this->actingAs($user, 'web');

        // Another Google account is refused.
        fakeGoogle(id: 'google-sub-someone-else', email: 'someone@example.test');
        $this->get('/auth/google/redirect?intent=confirm');
        $this->get('/auth/google/callback')->assertRedirect(route('account.delete'));
        $this->get('/account/delete')->assertSee('That is not the Google account linked to this account')->assertSee('name="password"', false);
        $this->post('/account/delete', ['understood' => '1'])->assertStatus(422);
        expect($user->fresh()->deletes_at)->toBeNull();

        // The linked one counts for 5 minutes.
        fakeGoogle(id: 'google-sub-linked', email: 'linked@example.test');
        $this->get('/auth/google/redirect?intent=confirm');
        $this->get('/auth/google/callback')->assertRedirect(route('account.delete'));
        $this->travel(GoogleSignIn::CONFIRMED_MINUTES + 1)->minutes();
        $this->get('/account/delete')->assertSee('name="password"', false);
        $this->post('/account/delete', ['understood' => '1'])->assertStatus(422);
        expect($user->fresh()->deletes_at)->toBeNull();

        // With two-step sign-in the code is still asked for: Google never stands in for it.
        [$secret] = accountsEnrolTotp($user);
        $this->get('/auth/google/redirect?intent=confirm');
        $this->get('/auth/google/callback');
        $this->get('/account/delete')->assertSee('name="code"', false)->assertDontSee('Confirm with Google')->assertDontSee('Confirmed with your Google account');
        $this->post('/account/delete', ['understood' => '1'])->assertStatus(422);
        expect($user->fresh()->deletes_at)->toBeNull();
        $this->post('/account/delete', ['code' => accountsTotp($user, $secret), 'understood' => '1'])->assertOk();
        expect($user->fresh()->deletes_at)->not->toBeNull();
    });

    it('tells an app whose account was made with Google where to confirm with Google', function () {
        $user = Fx::user();
        $user->forceFill(['google_id' => 'google-sub-app'])->save();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->deleteJson('/api/account', ['password' => 'wrong-password-1'])->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')->assertJsonPath('message', fn (string $m): bool => str_contains($m, route('account.delete')));
    });

    it('sends the owner of a team to the Workspace page', function () {
        $owner = Fx::user();
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), Fx::user());
        $this->actingAs($owner, 'web');

        $this->post('/account/delete', ['password' => 'password', 'understood' => '1'])->assertStatus(422)->assertSee('Open the Workspace page');
        expect($owner->fresh()->deletes_at)->toBeNull();
    });

    it('is throttled per address (429)', function () {
        foreach (range(1, 30) as $_) {
            $this->get('/account/delete');
        }
        $this->get('/account/delete')->assertStatus(429);
    });
});

describe('echoledger:erase-accounts', function () {
    it('erases an account past its date with every file it owned, and leaves its teams and teammates\' recordings', function () {
        $disk = Storage::disk('recordings');
        $user = Fx::user(['email' => 'gone@example.test']);
        $personal = Workspace::query()->findOrFail($user->current_workspace_id);
        [$device, $token] = $this->enroll($user);

        // A recording with audio, a transcript and a screen; another one already in the undo bin.
        $session = $this->makeReadySession($device, Fixtures::audio(4000));
        $session->transcripts()->create(['kind' => 'final', 'provider' => 'assemblyai', 'status' => 'completed', 'text' => 'Hello', 'segments' => [Fixtures::segment()]]);
        $picture = screenPicture();
        $plain = tempnam(sys_get_temp_dir(), 'scr');
        file_put_contents($plain, $picture);
        [$screen] = app(ScreenStore::class)->add($session, $plain, ScreenStore::sniff($plain) ?? [], ['at_ms' => 5000, 'width' => 1280, 'height' => 720, 'trigger' => 'manual', 'sha256' => hash('sha256', $picture)]);
        @unlink($plain);
        $binned = $this->makeReadySession($device, Fixtures::audio(3000, 'BINNED'));
        $binned->delete();

        // A project with a file, and a team where they are a member, with a teammate's recording filed in their project.
        $project = Client::factory()->create(['user_id' => $user->id, 'name' => 'Acme']);
        $doc = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($doc, 'PROJECT-FILE-SECRET');
        $file = app(ClientWorkspace::class)->storeFile($project, $doc, 'brief.txt', 'text/plain', 19, $user->id);
        @unlink($doc);
        $teamOwner = Fx::user();
        $team = Workspace::query()->findOrFail($teamOwner->current_workspace_id);
        app(Workspaces::class)->addMember($team, $user);
        $teamProject = unscoped(fn () => Client::factory()->create(['user_id' => $user->id, 'workspace_id' => $team->id, 'name' => 'Team project']));
        $theirs = unscoped(fn () => CaptureSession::factory()->create(['user_id' => $teamOwner->id, 'workspace_id' => $team->id, 'client_id' => $teamProject->id]));
        // A file they added to the team owner's project goes; the owner's own file there stays.
        $ownersProject = unscoped(fn () => Client::factory()->create(['user_id' => $teamOwner->id, 'workspace_id' => $team->id, 'name' => 'Owner project']));
        $storeIn = function (Client $in, string $text, int $by): ClientFile {
            $tmp = tempnam(sys_get_temp_dir(), 'doc');
            file_put_contents($tmp, $text);
            $stored = unscoped(fn () => app(ClientWorkspace::class)->storeFile($in, $tmp, 'notes.txt', 'text/plain', strlen($text), $by));
            @unlink($tmp);

            return $stored;
        };
        $addedToTeam = $storeIn($ownersProject, 'MEMBER-ADDED-SECRET', $user->id);
        $ownersFile = $storeIn($ownersProject, 'OWNER-FILE', $teamOwner->id);
        app(RequestLinks::class)->create($personal->id, $user->id, null, 'Send me files', null, 5, 50);
        $disk->put('exports/old.zip.elr', 'x');
        AccountExport::query()->create(['user_id' => $user->id, 'status' => 'ready', 'path' => 'exports/old.zip.elr', 'expires_at' => now()->addHour()]);

        $paths = unscoped(fn () => [
            ...Recording::query()->whereIn('capture_session_id', [$session->id, $binned->id])->pluck('stored_path')->all(),
            $screen->image_path, $screen->thumb_path, $file->path, $addedToTeam->path, 'exports/old.zip.elr',
        ]);
        expect($disk->exists((string) $ownersFile->path))->toBeTrue();
        foreach ($paths as $path) {
            expect($disk->exists((string) $path))->toBeTrue();
        }

        app(AccountDeletion::class)->schedule($user->fresh());
        // Not yet: the 7 days are not over.
        Artisan::call('echoledger:erase-accounts');
        expect(User::query()->find($user->id))->not->toBeNull();

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        expect(Artisan::call('echoledger:erase-accounts'))->toBe(0);

        foreach ($paths as $path) {
            expect($disk->exists((string) $path))->toBeFalse("{$path} is still on the disk");
        }
        expect($disk->exists((string) $ownersFile->path))->toBeTrue();
        unscoped(function () use ($user, $personal, $team, $theirs, $session, $binned, $file, $addedToTeam, $ownersFile, $ownersProject): void {
            expect(User::query()->find($user->id))->toBeNull()
                ->and(Workspace::query()->find($personal->id))->toBeNull()
                ->and(Workspace::query()->find($team->id))->not->toBeNull()
                ->and(CaptureSession::withTrashed()->whereIn('id', [$session->id, $binned->id])->count())->toBe(0)
                ->and(Transcript::query()->where('capture_session_id', $session->id)->count())->toBe(0)
                ->and(Screen::query()->where('capture_session_id', $session->id)->count())->toBe(0)
                ->and(Client::query()->where('user_id', $user->id)->count())->toBe(0)
                ->and(ClientFile::query()->find($file->id))->toBeNull()
                ->and(ClientFile::query()->find($addedToTeam->id))->toBeNull()
                ->and(ClientFile::query()->find($ownersFile->id))->not->toBeNull()
                ->and(Client::query()->find($ownersProject->id))->not->toBeNull()
                ->and(Device::query()->where('user_id', $user->id)->count())->toBe(0)
                ->and(RequestLink::query()->where('user_id', $user->id)->count())->toBe(0)
                ->and(AccountExport::query()->count())->toBe(0)
                ->and(PersonalAccessToken::query()->count())->toBe(0);
            // The teammate's recording stays, back in the Inbox.
            $kept = CaptureSession::query()->find($theirs->id);
            expect($kept)->not->toBeNull()->and($kept->client_id)->toBeNull();
        });
        expect(AuditEvent::query()->where('event', 'account_erased')->whereNull('user_id')->value('meta'))->toMatchArray(['recordings' => 2, 'workspaces' => 1]);
        expect(json_encode(AuditEvent::query()->get()))->not->toContain('gone@example.test');
    });

    it('hands a team to its longest member when someone joined during the 7 days', function () {
        $owner = Fx::user();
        $team = Workspace::query()->findOrFail($owner->current_workspace_id);
        $owner->forceFill(['deletes_at' => now()->subMinute(), 'deletion_requested_at' => now()->subDays(7)])->save();
        $member = Fx::user();
        app(Workspaces::class)->addMember($team, $member);
        $kept = unscoped(fn () => CaptureSession::factory()->create(['user_id' => $member->id, 'workspace_id' => $team->id]));

        Artisan::call('echoledger:erase-accounts');

        expect(User::query()->find($owner->id))->toBeNull()
            ->and(Workspace::query()->findOrFail($team->id)->roleOf($member))->toBe(WorkspaceRole::Owner)
            ->and(unscoped(fn () => CaptureSession::query()->find($kept->id)))->not->toBeNull();
    });

    it('is scheduled every hour', function () {
        $events = collect(app(Schedule::class)->events())->map(fn ($e): string => (string) $e->command);
        expect($events->first(fn (string $c): bool => str_contains($c, 'echoledger:erase-accounts')))->not->toBeNull()
            ->and($events->first(fn (string $c): bool => str_contains($c, 'echoledger:prune-exports')))->not->toBeNull();
    });
});
