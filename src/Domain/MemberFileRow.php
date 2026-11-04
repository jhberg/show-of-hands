<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * One row of the member file that makes a member.
 */
final readonly class MemberFileRow
{
    public function __construct(
        public MemberDetails $details,
        public ?string $voiceGroupKey,
        public bool $isBoard,
        public bool $isAdmin,
        public bool $isActive,
    ) {}
}
