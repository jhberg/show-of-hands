<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * The member took part in an open poll, whose count would lose their vote, or in a poll closed
 * today, which an admin may still reopen. They stay active until that poll is out of reach.
 */
final class DeactivationTooSoonException extends \RuntimeException
{
    /**
     * @param bool $open whether the poll is still open
     */
    public function __construct(int $memberId, public readonly bool $open)
    {
        parent::__construct("Member {$memberId} took part in a poll that is still counted with them.");
    }
}
