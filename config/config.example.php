<?php

declare(strict_types=1);

/*
 * Instance configuration. Copy this file to config/config.php and fill in the values.
 * config/config.php is never part of a release, so updates leave it alone.
 */

return [
    'app' => [
        // Fixed base URL, used for links and the Google redirect. No path, no trailing slash.
        'url' => 'https://vote.example.org',
        // At least 32 random bytes as hex. Keys the ballot tokens, so never change it while polls exist.
        // Generate one with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
        'secret' => '',
        // Time zone for dates on screen and for "the same day" rules.
        'timezone' => 'Europe/Berlin',
        // 'prod', or 'dev' for the development login. 'dev' is refused unless url points at localhost.
        'env' => 'prod',
    ],

    'locale' => [
        // Language for members who have not picked one: 'de' or 'en'.
        'default' => 'de',
    ],

    'google' => [
        // OAuth client from the Google Cloud Console. Not needed while app.env is 'dev'.
        'client_id' => '',
        'client_secret' => '',
        // Optional. Your Google Workspace domain, shown as a placeholder for email addresses.
        'workspace_domain' => 'example.org',
    ],

    'brand' => [
        // The choir's name, used in the page title and in texts such as "your {brand} account".
        'name' => 'Example Choir',
        // Header text when public/brand/ holds no header logo. Defaults to the name.
        'wordmark' => 'Example Choir',
        // Characters of the wordmark drawn in the accent colour, for example '*'.
        'wordmark_accent' => '',
        // The theme derives every colour from these two. Run bin/console brand:check after a change.
        'colors' => [
            'accent' => '#3b6fb6',
            'ink' => '#1f2433',
        ],
        // Optional self-hosted font. Put the files into public/brand/fonts/.
        'font' => [
            'family' => null,
            'files' => [
                // ['file' => 'Inter-Regular.woff2', 'weight' => 400],
                // ['file' => 'Inter-Bold.woff2', 'weight' => 700],
            ],
        ],
    ],

    // The first member, created with board and admin roles while the member list is empty.
    'bootstrap_admin' => [
        'name' => 'Alex Example',
        'email' => 'alex@example.org',
        'group' => 'S1',
    ],

    'session' => [
        // Members stay signed in this many days after their last visit.
        'lifetime_days' => 30,
    ],
];
