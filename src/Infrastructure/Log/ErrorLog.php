<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Log;

/**
 * Appends errors to data/logs/app.log.
 *
 * Lines name the exception and where it happened. They never contain request data,
 * so a ballot's choice cannot end up in the log.
 */
final readonly class ErrorLog
{
    public function __construct(private string $file) {}

    public function error(\Throwable $error): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o770, true);
        }
        $line = sprintf("[%s] %s: %s at %s:%d\n", gmdate('c'), $error::class, $error->getMessage(), $error->getFile(), $error->getLine());
        if (@file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(rtrim($line));
        }
    }
}
