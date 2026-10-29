<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Clock;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
