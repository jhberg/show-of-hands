<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * The member took part in a poll closed today, which an admin may still reopen, or in a reopened
 * poll that is open again. Its recount without them would reveal their choice, so the removal
 * waits until the day after that poll closes.
 */
final class RemovalTooSoonException extends \RuntimeException
{
    /**
     * @param bool $reopened whether the poll is a reopened one that is still open
     */
    public function __construct(int $memberId, public readonly bool $reopened)
    {
        parent::__construct("Member {$memberId} took part in a poll that may still be counted again.");
    }
}
