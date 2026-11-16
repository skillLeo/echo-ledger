<?php

declare(strict_types=1);

/*
 * Laravel Pennant decides the interface flags (App\Services\InterfaceFlags) in memory, per request: the rules live in
 * the interface_flags table, so nothing is stored per person.
 */
return [
    'default' => env('PENNANT_STORE', 'array'),

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],
    ],
];
