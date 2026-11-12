<?php

declare(strict_types=1);

// Config of the copy Apache serves for tests/apache/check.sh (compose service apache).
return [
    'app' => [
        'url' => 'http://localhost:8082',
        'secret' => str_repeat('a5', 32),
        'timezone' => 'Europe/Berlin',
        'env' => 'dev',
    ],
    'locale' => ['default' => 'de'],
    'brand' => ['name' => 'Example Choir'],
    'bootstrap_admin' => ['name' => 'Alex Example', 'email' => 'alex@example.org'],
];
