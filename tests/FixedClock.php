<?php

declare(strict_types=1);

namespace ShowOfHands\Tests;

use ShowOfHands\Infrastructure\Clock\Clock;

/**
 * A clock that always shows the same moment.
 */
final readonly class FixedClock implements Clock
{
    public function __construct(private \DateTimeImmutable $now) {}

    public static function at(string $time): self
    {
        return new self(new \DateTimeImmutable($time));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
