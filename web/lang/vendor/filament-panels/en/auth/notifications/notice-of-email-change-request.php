<?php

declare(strict_types=1);

// Since 2.0.0-l the change can be asked for in the apps too, confirmed with the password or a code; "Block" there also
// signs out everywhere, and whoever asked knew the password or a code.
return [

    'lines' => [
        0 => 'We received a request to change the email address of your account. Your password or a code was used to confirm it.',
        3 => 'If this wasn\'t you, block the change and then change your password: whoever asked knew it or a code. Blocking also signs you out on your other devices and browsers.',
    ],

    'action' => 'This wasn\'t me',

];
