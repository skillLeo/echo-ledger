<?php

declare(strict_types=1);

use App\Mail\DeletionScheduledMail;
use App\Mail\EmailCodeMail;
use App\Mail\ExportReadyMail;
use App\Mail\InvitationMail;
use App\Mail\LoginAlertMail;
use App\Mail\ResetPasswordMail;
use App\Mail\ShareReceivedMail;
use App\Mail\TestMail;
use App\Mail\TranscriptReadyMail;
use App\Mail\UnusualUsageMail;
use App\Mail\UpgradeDecidedMail;
use App\Mail\VerifyEmailMail;
use Illuminate\Mail\Mailable;

/*
| Every email has an HTML part with the mark and a plain-text part of its own: the same name and footer, readable as
| text (no Markdown marks, no table rules), and never Laravel's stock "All rights reserved" footer.
*/

/** @return array<string, Mailable> */
function everyMail(): array
{
    $u = 'https://example.test';

    return [
        'invitation' => new InvitationMail('Acme team', 'Hassam', "$u/register", true, 'Nov 15', false),
        'verify' => new VerifyEmailMail("$u/verify"),
        'reset' => new ResetPasswordMail("$u/reset"),
        'login alert' => new LoginAlertMail('Chrome on macOS', 'Nov 8', '203.0.113.7', 'Pakistan', "$u/profile"),
        'share' => new ShareReceivedMail('Hassam', 'Call with Omar', false, "$u/shared", 'For tomorrow'),
        'export' => new ExportReadyMail("$u/export", 'Nov 9', '12 MB'),
        'deletion' => new DeletionScheduledMail('Nov 15', "$u/admin"),
        'transcript' => new TranscriptReadyMail('Call with Omar', "$u/s/1"),
        'test' => new TestMail('smtp.example.test'),
        'email code' => new EmailCodeMail('123456', 'sign_up', 10),
        'upgrade approved' => new UpgradeDecidedMail(true, 'Pro', 'Dec 9, 2026', null, "$u/admin"),
        'upgrade declined' => new UpgradeDecidedMail(false, 'Pro', null, 'Payment not received yet.', "$u/admin"),
        'unusual usage' => new UnusualUsageMail('Nida', 'nida@example.test', 7.5, 6, "$u/admin/plans"),
    ];
}

it('gives every email the mark and a plain-text part that reads as text', function (string $name) {
    $mail = everyMail()[$name];
    $html = $mail->render();
    $views = (new ReflectionMethod($mail, 'buildView'))->invoke($mail);
    $text = (string) $views['text']($mail->buildViewData());

    expect($html)->toContain('/mail-mark.png')->toContain('EchoLedger')
        ->and($text)->toStartWith('EchoLedger')->toContain('Privacy: ')->toContain('Terms: ')
        ->not->toContain('All rights reserved')->not->toContain('Laravel')
        ->not->toMatch('/^#/m')->not->toContain('**')->not->toMatch('/^\|/m');
})->with(fn () => array_keys(everyMail()));
