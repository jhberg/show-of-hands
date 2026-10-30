<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * What a tap on Yes or No did: the ballot's outcome, and the delegation it ended, if any.
 */
final readonly class CastResult
{
    /**
     * @param int|null $reclaimedFrom the member who held the vote until the member voted themselves
     */
    public function __construct(
        public CastOutcome $outcome,
        public ?int $reclaimedFrom,
    ) {}
}
