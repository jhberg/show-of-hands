<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * A poll is open, or was closed today and may still be reopened. Until it is out of reach, no
 * member gets a new sign-in address, so nobody can sign in as a member and read or change
 * their vote.
 */
final class NewAddressTooSoonException extends \RuntimeException
{
    /**
     * @param bool $open whether a poll is still open
     */
    public function __construct(int $memberId, public readonly bool $open)
    {
        parent::__construct("Member {$memberId} cannot get a new address while a poll is in reach.");
    }
}
