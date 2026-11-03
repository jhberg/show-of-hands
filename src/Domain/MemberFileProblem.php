<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * A row of the member file that makes no member, with the value at fault where there is one.
 */
final readonly class MemberFileProblem
{
    public function __construct(
        public int $line,
        public MemberFileIssue|MemberDetailsError $issue,
        public string $value = '',
    ) {}
}
