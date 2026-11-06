<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Persistence;

/**
 * A connection that pauses before each COMMIT while it still holds the write lock, and says so
 * on stdout. ConcurrencyTest runs it in a second process, so its own competing write starts
 * while the lock is held and has to wait for it.
 */
final class CommitHoldingPdo extends \PDO
{
    public const HOLDING = 'holding';

    private const HOLD_MICROSECONDS = 300_000;

    public function exec(string $statement): false|int
    {
        if ($statement === 'COMMIT') {
            fwrite(STDOUT, self::HOLDING . "\n");
            fflush(STDOUT);
            usleep(self::HOLD_MICROSECONDS);
        }

        return parent::exec($statement);
    }
}
