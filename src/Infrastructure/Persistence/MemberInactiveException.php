<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * An inactive member tried to vote or to hand their vote on. Only active members are eligible.
 */
final class MemberInactiveException extends \RuntimeException
{
    public function __construct(int $memberId)
    {
        parent::__construct("Member {$memberId} is inactive.");
    }
}
