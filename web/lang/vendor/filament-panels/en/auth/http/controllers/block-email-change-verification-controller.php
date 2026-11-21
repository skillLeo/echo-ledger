<?php

declare(strict_types=1);

// Since 2.0.0-l: whoever asked for the change knew the password or a code, so the page says to change the password.
return [

    'notifications' => [

        'blocked' => [
            'title' => 'Email change blocked',
            'body' => 'The change to :email is blocked and you are signed out on your other devices and browsers. Whoever asked knew your password or a code: change your password now, in Settings › Sign-in & security.',
        ],

    ],

];
