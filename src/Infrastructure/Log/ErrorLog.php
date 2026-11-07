<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Log;

use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Infrastructure\Storage\DataDirectory;

/**
 * Appends errors to data/logs/app.log.
 *
 * Lines name the request's method and path, the exception, and where it happened. They never
 * contain a query string, a body, or a header, so a ballot's choice cannot end up in the log.
 *
 * Past MAX_BYTES the log moves to app.log.1, replacing the one before. It rotates as it writes,
 * not in maintenance, because a failing migration stops maintenance and writes a line on every
 * request, including every phone's live refresh.
 */
final readonly class ErrorLog
{
    public const MAX_BYTES = 1_000_000;

    public function __construct(private string $file) {}

    public function error(\Throwable $error, ServerRequestInterface $request): void
    {
        try {
            DataDirectory::create(dirname($this->file));
        } catch (\RuntimeException) {
            // The write below fails too and falls back to PHP's own log.
        }
        $this->rotate();
        $line = sprintf(
            "[%s] %s %s: %s at %s:%d\n",
            gmdate('c'),
            self::requestLine($request),
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
        );
        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }

    private function rotate(): void
    {
        clearstatcache(true, $this->file);
        $size = @filesize($this->file);
        if ($size !== false && $size >= self::MAX_BYTES) {
            @rename($this->file, $this->file . '.1');
        }
    }

    /**
     * The method and the path, without the query. Anything but printable ASCII becomes "?", so a
     * crafted path cannot forge a line.
     */
    private static function requestLine(ServerRequestInterface $request): string
    {
        $printable = static fn(string $text): string => (string) preg_replace('/[^\x21-\x7E]/', '?', $text);

        return $printable($request->getMethod()) . ' ' . $printable($request->getUri()->getPath());
    }
}
