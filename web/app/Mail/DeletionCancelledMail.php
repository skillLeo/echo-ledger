<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;

/** "Keep my account" was chosen in an app's sign-in (since 2.0.0-l): the account is no longer being deleted. */
final class DeletionCancelledMail extends AccountMail
{
    protected function subjectLine(): string
    {
        return 'Your '.Brand::name().' account is kept';
    }

    protected function template(): string
    {
        return 'mail.deletion-cancelled';
    }

    protected function data(): array
    {
        return [];
    }
}
