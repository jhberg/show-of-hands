<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/** The share of Yes votes a poll needs, of the voting rights its majority counts from. */
enum MajorityShare: string
{
    case Half = 'half';
    case TwoThirds = 'two_thirds';
    case ThreeQuarters = 'three_quarters';

    /**
     * The Yes votes needed out of $total, at least one: more than half, or at least two thirds
     * or three quarters, rounded up.
     */
    public function needed(int $total): int
    {
        return max(1, match ($this) {
            self::Half => intdiv($total, 2) + 1,
            self::TwoThirds => intdiv(2 * $total + 2, 3),
            self::ThreeQuarters => intdiv(3 * $total + 3, 4),
        });
    }
}
