<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * What a tap on Yes or No did to the member's ballot.
 */
enum CastOutcome
{
    /** The member had no ballot and now has one. */
    case Registered;

    /** The member switched to the other choice. */
    case Changed;

    /** The member tapped the choice they already had. */
    case Unchanged;

    public static function of(?Choice $previous, Choice $choice): self
    {
        return match ($previous) {
            null => self::Registered,
            $choice => self::Unchanged,
            default => self::Changed,
        };
    }
}
