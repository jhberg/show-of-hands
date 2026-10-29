<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Database;

final class Transaction
{
    /**
     * Runs $work in a BEGIN IMMEDIATE transaction, which takes the write lock up front,
     * so checks inside it still hold when it commits.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public static function immediate(\PDO $pdo, callable $work): mixed
    {
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $work();
            $pdo->exec('COMMIT');

            return $result;
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
    }
}
