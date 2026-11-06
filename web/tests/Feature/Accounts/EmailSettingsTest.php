<?php

declare(strict_types=1);

use App\Filament\Pages\Settings;
use App\Mail\AccountMail;
use App\Mail\DeletionScheduledMail;
use App\Mail\ExportReadyMail;
use App\Mail\InvitationMail;
use App\Mail\LoginAlertMail;
use App\Mail\ResetPasswordMail;
use App\Mail\ShareReceivedMail;
use App\Mail\TestMail;
use App\Mail\TranscriptReadyMail;
use App\Mail\VerifyEmailMail;
use App\Models\AuditEvent;
use App\Models\Credential;
use App\Services\CredentialVault;
use App\Services\MailSettings;
use App\Services\SettingsRepository;
use App\Services\SettingsSearch;
use App\Services\SettingsTransfer;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(fn () => Filament::setCurrentPanel('admin'));

const SMTP_PASSWORD = 'smtp-Secret-pass-4821';

function emailSettingsPage($test)
{
    return $test->panelComponent(Settings::class)->set('activeTab', 'email');
}

function testEmailAction(): TestAction
{
    return TestAction::make('sendTestEmail')->schemaComponent('email.smtp-actions', schema: 'form');
}

/** @return Collection<int, Notification> the notifications the page sent (read before assertNotified pulls them) */
function sentNotifications(): Collection
{
    app(NotificationMailbox::class)->flush();
    $component = new Notifications;
    $component->mount();
    $component->pullNotificationsFromSession();

    return collect($component->notifications->all());
}

/** The transport the default mailer would send with (made, never connected). */
function defaultSmtpTransport(): EsmtpTransport
{
    $transport = Mail::mailer()->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(EsmtpTransport::class);

    return $transport;
}

it('saves the SMTP server, keeps the password encrypted in the vault and never shows it again', function () {
    $this->signInAsAdmin();

    emailSettingsPage($this)->assertSee('SMTP server')->assertSee('Send a test email')
        ->fillForm(['mail_host' => 'SMTP.Example.com', 'mail_port' => 587, 'mail_encryption' => 'tls', 'mail_username' => 'mailer@example.com',
            'mail_password' => SMTP_PASSWORD, 'mail_from_address' => 'echoledger@example.com', 'mail_from_name' => 'Echo Team'])
        ->call('save')->assertHasNoFormErrors()
        ->assertSet('data.mail_password', null)->assertDontSee(SMTP_PASSWORD);

    $settings = app(SettingsRepository::class);
    $settings->flush();
    expect($settings->get('mail.host'))->toBe('smtp.example.com')
        ->and($settings->get('mail.port'))->toBe(587)
        ->and($settings->get('mail.username'))->toBe('mailer@example.com')
        ->and($settings->get('mail.from_name'))->toBe('Echo Team')
        ->and(app(CredentialVault::class)->get('mail', 'password'))->toBe(SMTP_PASSWORD)
        ->and((string) DB::table('credentials')->where(['provider' => 'mail', 'key' => 'password'])->value('value'))->not->toContain(SMTP_PASSWORD)
        ->and(DB::table('settings')->pluck('value')->implode(' '))->not->toContain(SMTP_PASSWORD)
        ->and(json_encode(app(SettingsTransfer::class)->export()))->not->toContain(SMTP_PASSWORD)->toContain('smtp.example.com');
    // The change is in the audit log, without the value.
    expect(AuditEvent::query()->where('event', 'credentials_changed')->where('summary', 'like', '%mail%')->exists())->toBeTrue()
        ->and(AuditEvent::query()->pluck('meta')->toJson())->not->toContain(SMTP_PASSWORD);

    // An empty password keeps the stored one; "Remove the stored password" forgets it.
    $page = emailSettingsPage($this)->fillForm(['mail_from_name' => 'Echo'])->call('save')->assertHasNoFormErrors();
    expect(app(CredentialVault::class)->get('mail', 'password'))->toBe(SMTP_PASSWORD);
    $page->fillForm(['mail_password_forget' => true])->call('save')->assertHasNoFormErrors();
    expect(Credential::query()->where('provider', 'mail')->exists())->toBeFalse();
});

it('refuses a host, port or sender that cannot work', function (array $fields, string $error) {
    $this->signInAsAdmin();

    emailSettingsPage($this)->fillForm($fields)->call('save')->assertHasFormErrors([$error]);
})->with([
    'host with a scheme' => [['mail_host' => 'smtp://mail.example.com'], 'mail_host'],
    'port out of range' => [['mail_host' => 'mail.example.com', 'mail_port' => 70000], 'mail_port'],
    'sender not an email' => [['mail_host' => 'mail.example.com', 'mail_from_address' => 'not-an-email'], 'mail_from_address'],
]);

it('sends every email through the saved SMTP server, with its encryption and sender', function () {
    $this->signInAsAdmin();
    app(CredentialVault::class)->put('mail', 'password', SMTP_PASSWORD);

    emailSettingsPage($this)->fillForm(['mail_host' => 'mail.example.com', 'mail_port' => 2525, 'mail_encryption' => 'tls',
        'mail_username' => 'mailer', 'mail_from_address' => 'echoledger@example.com', 'mail_from_name' => 'EchoLedger'])
        ->call('save')->assertHasNoFormErrors();

    $transport = defaultSmtpTransport();
    expect(config('mail.default'))->toBe('smtp')
        ->and($transport->getStream()->getHost())->toBe('mail.example.com')
        ->and($transport->getStream()->getPort())->toBe(2525)
        ->and($transport->getStream()->isTLS())->toBeFalse()
        ->and($transport->isTlsRequired())->toBeTrue()
        ->and($transport->getUsername())->toBe('mailer')
        ->and($transport->getPassword())->toBe(SMTP_PASSWORD)
        ->and(config('mail.from'))->toBe(['address' => 'echoledger@example.com', 'name' => 'EchoLedger']);

    // SSL/TLS = TLS from the first byte; None never tries STARTTLS.
    emailSettingsPage($this)->fillForm(['mail_encryption' => 'ssl', 'mail_port' => 465])->call('save');
    expect(defaultSmtpTransport()->getStream()->isTLS())->toBeTrue();
    emailSettingsPage($this)->fillForm(['mail_encryption' => 'none', 'mail_port' => 25])->call('save');
    expect(defaultSmtpTransport()->isAutoTls())->toBeFalse()->and(defaultSmtpTransport()->isTlsRequired())->toBeFalse();

    // No server: back to the .env mailer and sender.
    emailSettingsPage($this)->fillForm(['mail_host' => null])->call('save')->assertHasNoFormErrors();
    expect(config('mail.default'))->toBe('array')->and(config('mail.from.address'))->not->toBe('echoledger@example.com');
});

it('gives a running queue worker the latest SMTP server before each queued email', function () {
    app(MailSettings::class)->apply();
    expect(config('mail.default'))->toBe('array');

    // Saved by the console while the worker runs: the worker's next email picks it up.
    app(SettingsRepository::class)->setMany(['mail.host' => 'relay.example.com', 'mail.port' => 587]);
    $job = fn (string $command) => new SyncJob(app(), (string) json_encode(['data' => ['commandName' => $command]]), 'sync', 'default');
    event(new JobProcessing('sync', $job('App\Jobs\TranscribeJob')));
    expect(config('mail.default'))->toBe('array');
    event(new JobProcessing('sync', $job(SendQueuedMailable::class)));
    expect(config('mail.default'))->toBe('smtp')->and(defaultSmtpTransport()->getStream()->getHost())->toBe('relay.example.com');
});

it('sends a test email at once with the values on the page, saved or not, and logs it', function () {
    Mail::fake();
    $admin = $this->signInAsAdmin();
    app(CredentialVault::class)->put('mail', 'password', SMTP_PASSWORD);

    emailSettingsPage($this)->fillForm(['mail_host' => 'unsaved.example.com', 'mail_port' => 587, 'mail_encryption' => 'tls', 'mail_username' => 'mailer',
        'mail_from_address' => 'echoledger@example.com', 'mail_from_name' => 'EchoLedger'])
        ->callAction(testEmailAction(), ['to' => 'ops@example.com'])
        ->assertHasNoActionErrors()->assertNotified('Test email sent');

    Mail::assertSent(TestMail::class, fn (TestMail $mail): bool => $mail->hasTo('ops@example.com') && $mail->host === 'unsaved.example.com'
        && $mail->mailer === MailSettings::TEST_MAILER && ($mail->from[0]['address'] ?? null) === 'echoledger@example.com');
    Mail::assertNotQueued(TestMail::class);
    expect(app(SettingsRepository::class)->get('mail.host'))->toBeNull()
        ->and(config('mail.mailers.'.MailSettings::TEST_MAILER))->toBeNull()
        ->and(AuditEvent::query()->where(['event' => 'test_email_sent', 'user_id' => $admin->id])->exists())->toBeTrue();
});

it('offers the signed-in address and needs an SMTP server for a test email', function () {
    Mail::fake();
    $admin = $this->signInAsAdmin();

    emailSettingsPage($this)->mountAction(testEmailAction())->assertSchemaStateSet(['to' => $admin->email]);
    emailSettingsPage($this)->callAction(testEmailAction(), ['to' => 'ops@example.com'])->assertNotified('Enter the SMTP server and an address first');
    Mail::assertNothingSent();
});

it('shows the SMTP server\'s error for a failed test email, never the password', function () {
    $this->signInAsAdmin();
    app(CredentialVault::class)->put('mail', 'password', SMTP_PASSWORD);

    // Nothing listens on port 1 of this machine: the connection is refused at once.
    emailSettingsPage($this)->fillForm(['mail_host' => '127.0.0.1', 'mail_port' => 1, 'mail_encryption' => 'none', 'mail_username' => 'mailer'])
        ->callAction(testEmailAction(), ['to' => 'ops@example.com']);

    $sent = sentNotifications();
    expect($sent->map(fn (Notification $n): ?string => $n->getTitle())->all())->toContain('The test email was not sent')
        ->and($sent->map(fn (Notification $n): string => (string) $n->getBody())->implode(' '))->toContain('127.0.0.1')->not->toContain(SMTP_PASSWORD)
        ->and(AuditEvent::query()->where('event', 'test_email_sent')->exists())->toBeFalse();
});

it('allows 5 test emails a minute', function () {
    Mail::fake();
    $this->signInAsAdmin();
    $page = emailSettingsPage($this)->fillForm(['mail_host' => 'mail.example.com']);

    foreach (range(1, 5) as $_) {
        $page->callAction(testEmailAction(), ['to' => 'ops@example.com'])->assertNotified('Test email sent');
    }
    $page->callAction(testEmailAction(), ['to' => 'ops@example.com'])->assertNotified('Too many test emails');
    Mail::assertSentCount(5);
});

it('keeps Settings → Email to the super admin', function () {
    $this->actingAs($this->makeRegularUser(), 'web');

    $this->get(Settings::getUrl())->assertForbidden();
});

it('finds the email settings by search', function () {
    expect(SettingsSearch::results('smtp'))->toBe(['email'])
        ->and(SettingsSearch::results('dkim'))->toBe(['email'])
        ->and(SettingsSearch::firstGroup('test email'))->toBe('email');
});

it('queues every account email and draws it in the console theme', function (AccountMail $mail, string $text) {
    expect($mail)->toBeInstanceOf(ShouldQueue::class);

    $html = $mail->render();
    expect($html)->toContain($text)->toContain('#3E63DD')->toContain('EchoLedger');
})->with([
    'verify email' => [new VerifyEmailMail('https://ledger.test/verify/1'), 'https://ledger.test/verify/1'],
    'reset password' => [new ResetPasswordMail('https://ledger.test/reset/1'), 'https://ledger.test/reset/1'],
    'invitation' => [new InvitationMail('Acme team', 'Hassam', 'https://ledger.test/invite/1', true), 'Acme team'],
    'login alert' => [new LoginAlertMail('S25 Ultra, Android', 'Nov 6, 2026 10:00', '203.0.113.9', 'PK', 'https://ledger.test/admin/profile'), 'S25 Ultra, Android'],
    'share received' => [new ShareReceivedMail('Hassam', 'Weekly call', false, 'https://ledger.test/admin/shared/1'), 'Weekly call'],
    'transcript ready' => [new TranscriptReadyMail('Weekly call', 'https://ledger.test/admin/sessions/1'), 'Weekly call'],
    'export ready' => [new ExportReadyMail('https://ledger.test/export/1', 'Nov 7, 2026 10:00', '12 MB'), 'https://ledger.test/export/1'],
    'deletion scheduled' => [new DeletionScheduledMail('Nov 13, 2026', 'https://ledger.test/admin'), 'Nov 13, 2026'],
]);
