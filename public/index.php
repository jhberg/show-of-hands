<?php

declare(strict_types=1);

use ShowOfHands\Http\AppFactory;
use ShowOfHands\Infrastructure\Config\ConfigException;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Paths;

$root = dirname(__DIR__);

header_remove('X-Powered-By');

// PHP's built-in dev server sends every request here. Let it serve existing files itself.
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (is_string($uri) && is_file(__DIR__ . parse_url($uri, PHP_URL_PATH))) {
        return false;
    }
}

require $root . '/vendor/autoload.php';

$paths = new Paths($root);
try {
    $config = ConfigLoader::fromFile($paths->config());
} catch (ConfigException $e) {
    // Without a valid config there is no brand and no translator, so this page stays plain.
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'");
    $items = implode('', array_map(
        static fn(string $error): string => '<li>' . htmlspecialchars($error, ENT_QUOTES) . '</li>',
        $e->errors,
    ));
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Configuration error</title>',
    '<h1>Configuration error</h1><p>Fix these problems in config/config.php:</p><ul>', $items, '</ul></html>';

    return;
}

AppFactory::create($paths, $config)->run();
