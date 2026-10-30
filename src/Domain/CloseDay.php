<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The day a poll closed, in the choir's time zone. Until it ends, an admin may reopen the poll,
 * so its ballots stay. Afterwards only the frozen tally is needed.
 */
final class CloseDay
{
    public static function hasEnded(\DateTimeImmutable $closedAt, \DateTimeImmutable $now, \DateTimeZone $zone): bool
    {
        return $now->setTimezone($zone)->format('Y-m-d') > $closedAt->setTimezone($zone)->format('Y-m-d');
    }
}
