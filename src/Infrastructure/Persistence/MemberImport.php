<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\MemberFileProblem;
use ShowOfHands\Domain\MemberFileRow;

/**
 * What reading the member file adds, or would add: the rows whose addresses no member uses yet,
 * and the display names it fills in for members already on the list who have none. Otherwise
 * those members stay as they are, and rows with a problem are left out.
 */
final readonly class MemberImport
{
    /**
     * @param list<MemberFileRow> $added
     * @param list<array{member: Member, display_name: string}> $named
     * @param int $existing the other rows of members already on the list
     * @param list<MemberFileProblem> $problems
     */
    public function __construct(
        public array $added,
        public array $named,
        public int $existing,
        public array $problems,
    ) {}
}
