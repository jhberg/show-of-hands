<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Database;

/**
 * Opens the SQLite database with the pragmas every connection needs.
 */
final class ConnectionFactory
{
    /**
     * @param class-string<\PDO> $class a subclass for tests that watch the queries
     */
    public static function open(string $path, string $class = \PDO::class): \PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the data directory {$dir}.");
        }
        $pdo = new $class('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // secure_delete overwrites freed pages, so changed or deleted ballots leave no trace.
        $pdo->exec('PRAGMA secure_delete = ON');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }
}
