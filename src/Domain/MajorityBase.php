<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/** What a majority counts from. */
enum MajorityBase: string
{
    /** All eligible voters: whoever does not vote counts as an abstention, which works like a No. */
    case Eligible = 'eligible';

    /** Only the voting rights cast: whoever does not vote does not count. */
    case Cast = 'cast';
}
