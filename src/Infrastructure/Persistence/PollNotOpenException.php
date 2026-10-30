<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * A write that needs an open poll found it closed, unpublished, or deleted.
 */
final class PollNotOpenException extends \RuntimeException
{
    public function __construct(int $pollId)
    {
        parent::__construct("Poll {$pollId} is not open.");
    }
}
