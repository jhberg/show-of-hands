<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The rule a poll passes by, such as two thirds of all eligible voters. Admins choose it in the
 * settings; each poll keeps the rule it was created with, so a change never alters a running or
 * closed poll.
 */
final readonly class Majority
{
    public function __construct(
        public MajorityShare $share,
        public MajorityBase $base,
    ) {}

    /** The rule of a new installation, and of every poll created before the rule was a setting. */
    public static function default(): self
    {
        return new self(MajorityShare::TwoThirds, MajorityBase::Eligible);
    }
}
