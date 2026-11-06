<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Pages\Settings;
use App\Filament\Pages\WorkspaceSettings;
use App\Mail\LoginAlertMail;
use App\Mail\ShareReceivedMail;
use App\Mail\TranscriptReadyMail;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\KnownBrowser;
use App\Models\Workspace;
use App\Services\Accounts\KnownBrowsers;
use App\Services\PairingService;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/account_helpers.php';

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

const ALERT_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

it('emails the account when the console is opened from a browser it has not used, not from a known one', function () {
    $user = Fx::user(['email' => 'farah@example.test']);
    $user->forceFill(['google_id' => 'google-sub-1'])->save();
    fakeGoogle();
    $cookie = KnownBrowsers::cookieName($user);

    // The account's first browser is where it starts: no email, but the browser is remembered.
    $first = $this->withHeader('User-Agent', ALERT_CHROME)->get('/auth/google/callback')->assertRedirect();
    $token = $first->getCookie($cookie)?->getValue();
    expect($token)->toBeString()->not->toBe('')
        ->and(KnownBrowser::query()->where('user_id', $user->id)->count())->toBe(1);
    Mail::assertNotQueued(LoginAlertMail::class);

    // Another browser (no token of this account).
    auth('web')->logout();
    $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15')->get('/auth/google/callback')->assertRedirect();
    Mail::assertQueued(LoginAlertMail::class, fn (LoginAlertMail $mail): bool => $mail->hasTo('farah@example.test') && $mail->what === 'Safari on macOS' && str_contains($mail->devicesUrl, '/admin/profile#devices'));

    // The first browser again, with its token.
    auth('web')->logout();
    $this->withCookie($cookie, (string) $token)->withHeader('User-Agent', ALERT_CHROME)->get('/auth/google/callback')->assertRedirect();
    Mail::assertQueuedCount(1);
    expect(KnownBrowser::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('emails the account about a device paired with a code', function () {
    $user = Fx::user(['email' => 'pairing@example.test']);
    $code = app(PairingService::class)->createCode($user);

    $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Pixel 9', 'platform' => 'android'])->assertCreated();

    Mail::assertQueued(LoginAlertMail::class, fn (LoginAlertMail $mail): bool => $mail->hasTo('pairing@example.test') && $mail->what === 'Pixel 9, Android');
});

it('records a burst of failed console sign-ins once, and counts it against the apps\' sign-in too', function () {
    $user = Fx::user(['email' => 'burst@example.test']);

    foreach (range(1, 5) as $i) {
        Livewire::test(Login::class)->fillForm(['email' => 'burst@example.test', 'password' => 'wrong-password-'.$i])->call('authenticate');
    }

    expect(AuditEvent::query()->where('event', 'login_failed')->where('user_id', $user->id)->count())->toBe(5)
        ->and(AuditEvent::query()->where('event', 'sign_in_locked')->count())->toBe(1);
    $this->postJson('/api/auth/password', ['email' => 'burst@example.test', 'password' => 'password', 'name' => 'Phone', 'platform' => 'android'])
        ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
});

it('emails a teammate who was sent a recording and logs the sending', function () {
    $sender = Fx::user(['name' => 'Farah']);
    $recipient = Fx::user(['email' => 'teammate@example.test']);
    $session = CaptureSession::factory()->create(['user_id' => $sender->id, 'title' => 'Budget call']);

    app(Teammates::class)->send($sender, $session, ['Teammate@Example.test'], 'Have a listen');

    Mail::assertQueued(ShareReceivedMail::class, fn (ShareReceivedMail $mail): bool => $mail->hasTo('teammate@example.test') && $mail->sender === 'Farah' && $mail->what === 'Budget call' && $mail->note === 'Have a listen' && ! $mail->project);
    expect(AuditEvent::query()->where('event', 'sent_to_teammate')->where('user_id', $sender->id)->value('summary'))->toContain('teammate@example.test');
});

it('emails a finished transcript only to people who asked for it in their profile', function () {
    $user = Fx::user(['email' => 'reader@example.test']);
    $this->actingAs($user, 'web');
    Livewire::test(EditProfile::class)->callAction(TestAction::make('toggleTranscriptEmails')->schemaComponent('email-actions', schema: 'content'))
        ->assertNotified('Transcript emails on');
    expect($user->fresh()->notify_transcript_ready)->toBeTrue();

    $session = CaptureSession::factory()->create(['user_id' => $user->id, 'title' => 'Site visit']);
    $transcript = $session->transcripts()->create(['kind' => 'final', 'provider' => 'assemblyai', 'status' => 'processing']);
    $transcript->update(['status' => 'completed', 'text' => 'Done']);
    Mail::assertQueued(TranscriptReadyMail::class, fn (TranscriptReadyMail $mail): bool => $mail->hasTo('reader@example.test') && $mail->title === 'Site visit' && str_ends_with($mail->url, '/admin/sessions/'.$session->id));

    $quiet = Fx::user();
    $other = CaptureSession::factory()->create(['user_id' => $quiet->id]);
    $other->transcripts()->create(['kind' => 'final', 'provider' => 'assemblyai', 'status' => 'processing'])->update(['status' => 'completed']);
    Mail::assertQueuedCount(1);
});

it('puts admin actions in the audit log: members, roles, the workspace name and the settings', function () {
    $owner = Fx::superAdmin();
    $member = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Workspaces::class)->addMember($workspace, $member, WorkspaceRole::Member);
    $this->actingAs($owner, 'web');

    Livewire::test(WorkspaceSettings::class)
        ->callAction(TestAction::make('changeRole')->table($member))
        ->callAction(TestAction::make('remove')->table($member->fresh()))
        ->callAction('rename', ['name' => 'Renamed team']);
    Livewire::test(Settings::class)->fillForm(['retention_days' => 14])->call('save');

    $events = AuditEvent::query()->where('user_id', $owner->id)->pluck('event')->all();
    expect($events)->toContain('role_changed', 'member_removed', 'workspace_renamed', 'settings_changed')
        ->and(AuditEvent::query()->where('event', 'settings_changed')->value('meta'))->toBe(['keys' => ['retention_days']]);
});
