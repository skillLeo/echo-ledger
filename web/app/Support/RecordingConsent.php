<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The recording-consent notice (since v1.5): the words the phone app shows when it is set up, shown once after
 * sign-up on the console and kept in the profile. Change them here and in the apps together.
 */
final class RecordingConsent
{
    public const TEXT = 'Call-recording laws differ by country. Tell participants you are recording where required.';

    public const APPS = 'While EchoLedger records, the phone always shows a recording notification and the computer a red indicator.';

    public const CONFIRM = 'I understand and will get consent where required';
}
