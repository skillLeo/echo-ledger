<?php

declare(strict_types=1);

/*
| Cross-origin requests are not needed: the device apps call the API directly (not from a browser page)
| and the console is same-origin. With no paths, the framework's HandleCors middleware adds no CORS headers.
*/
return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
