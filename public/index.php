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

// The document root must be public/. A host that serves the installation folder, or a folder above it,
// leaves config/ and data/ to the .htaccess rules alone, and nginx in front of Apache reads none of them.
// Mass hosting can report a document root that is not the domain's, so the check trusts it only when it
// and the script's path together lead to this file. PHP's built-in server names the request's path as
// the script, and its document root is always the one it was started with.
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$documentRoot = is_string($documentRoot) && $documentRoot !== '' && is_string($scriptName)
    && (PHP_SAPI === 'cli-server' || realpath($documentRoot . $scriptName) === __FILE__) ? realpath($documentRoot) : false;
if ($documentRoot !== false && $documentRoot !== __DIR__ && str_starts_with($root . '/', rtrim($documentRoot, '/') . '/')) {
    renderStartError(
        'Wrong document root',
        'The web server serves the installation folder instead of its public/ folder, so the app stops. '
        . 'Only public/ may be reachable from the web: config/ holds the app\'s secret and the member file, data/ the database. '
        . 'Point the document root of this domain at the public/ folder:',
        [
            'In Plesk: Websites & Domains, the domain, Hosting settings, field "Document root". Add /public to the folder there, for example vote.example.org/public.',
            'Elsewhere: in the domain settings of your web space, or ask your host.',
            'Then open the site again.',
        ],
    );

    return;
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
