<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\MemberFileProblem;
use ShowOfHands\Domain\MemberFileRow;

/**
 * What reading the member file adds, or would add: the rows whose addresses no member uses yet.
 * Rows of members already on the list stay as they are, and rows with a problem are left out.
 */
final readonly class MemberImport
{
    /**
     * @param list<MemberFileRow> $added
     * @param list<MemberFileProblem> $problems
     */
    public function __construct(
        public array $added,
        public int $existing,
        public array $problems,
    ) {}
}
