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
];
