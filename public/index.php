<?php

declare(strict_types=1);

use ShowOfHands\Http\AppFactory;
use ShowOfHands\Infrastructure\Config\ConfigException;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Infrastructure\Storage\DataDirectory;
use ShowOfHands\Paths;

$root = dirname(__DIR__);

header_remove('X-Powered-By');

// A warning on the page would show server paths. Only development shows them, once the config says so.
ini_set('display_errors', '0');

// PHP's built-in dev server sends every request here. Let it serve existing files itself.
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (is_string($uri) && is_file(__DIR__ . parse_url($uri, PHP_URL_PATH))) {
        return false;
    }
}

require $root . '/vendor/autoload.php';

/**
 * A page for a problem the app cannot start with: no brand and no translator yet, so it stays plain.
 *
 * @param list<string> $items
 */
function renderStartError(string $title, string $text, array $items): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    $list = implode('', array_map(static fn(string $item): string => '<li>' . htmlspecialchars($item, ENT_QUOTES) . '</li>', $items));
    $title = htmlspecialchars($title, ENT_QUOTES);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>', $title, '</title>',
    '<h1>', $title, '</h1><p>', htmlspecialchars($text, ENT_QUOTES), '</p><ul>', $list, '</ul></html>';
}

$paths = Paths::fromEnvironment($root);
try {
    $config = ConfigLoader::fromFile($paths->config());
} catch (ConfigException $e) {
    renderStartError('Configuration error', 'Fix these problems in config/config.php:', $e->errors);

    return;
}
if ($config->isDev()) {
    ini_set('display_errors', '1');
}

$unwritable = DataDirectory::unwritable($paths);
if ($unwritable !== []) {
    renderStartError(
        'Data folder not writable',
        'The app keeps its database, sessions, and log in the folder data/ and cannot write there. '
        . 'Give PHP write access to data/ and everything in it, in your FTP program or the file manager of your web space. '
        . 'Where PHP runs as your own user, the owner needs read, write, and execute rights (700) on folders. '
        . 'Otherwise, ask your host which user PHP runs as. These need write access:',
        // Named from data/ on, so the page shows no server path.
        array_map(static fn(string $path): string => 'data' . substr($path, strlen($paths->data())), $unwritable),
    );

    return;
}

AppFactory::create($paths, $config)->run();
