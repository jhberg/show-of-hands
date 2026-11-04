<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * The member took part in an open poll, whose count would lose their vote, or in a poll closed
 * today, which an admin may still reopen and whose recount without them would reveal their
 * choice. The removal waits until the day after that poll closes.
 */
final class RemovalTooSoonException extends \RuntimeException
{
    /**
     * @param bool $open whether the poll is still open
     */
    public function __construct(int $memberId, public readonly bool $open)
    {
        parent::__construct("Member {$memberId} took part in a poll that is still counted with them.");
    }
}
