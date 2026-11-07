<?php

declare(strict_types=1);

// Config of the copy the Playwright journeys run against (compose service e2e-app).
return [
    'app' => [
        'url' => 'http://localhost:8081',
        'secret' => str_repeat('e2', 32),
        'timezone' => 'Europe/Berlin',
        'env' => 'dev',
    ],
    'locale' => ['default' => 'de'],
    'brand' => ['name' => 'Example Choir'],
    // Ignored once the seed has filled the member list. The upgrade test runs a release with this
    // config, so its compiled container has to cope with the object this key becomes.
    'bootstrap_admin' => ['name' => 'Alex Example', 'email' => 'alex@example.org'],
];
