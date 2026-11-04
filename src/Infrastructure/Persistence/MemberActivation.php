<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Member;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Sets a member inactive or active again, in one BEGIN IMMEDIATE transaction. The open polls
 * count only active members, so the change moves their eligible voters and the Yes votes they
 * need. Closed polls keep their frozen tally and their turnout snapshot.
 *
 * A member who voted, delegated, or received a vote in an open poll stays active until it
 * closes: dropping them would take a cast vote out of the count, so an admin could remove votes
 * they expect to dislike. After a poll closed today, they stay active until the next day, since
 * an admin may still reopen it and its recount would leave their ballot without a voter.
 */
final readonly class MemberActivation
{
    public function __construct(
        private \PDO $pdo,
        private MemberRepository $members,
    ) {}

    /**
     * @return Member|null the member as they were before, or null when they no longer exist
     *
     * @throws DeactivationTooSoonException
     */
    public function set(int $memberId, bool $active, \DateTimeImmutable $now, \DateTimeZone $zone): ?Member
    {
        return Transaction::immediate($this->pdo, function () use ($memberId, $active, $now, $zone): ?Member {
            $member = $this->members->find($memberId);
            if ($member === null) {
                return null;
            }
            if (!$active && $member->isActive) {
                $open = PollsTakenPart::hold($this->pdo, $memberId, $now, $zone);
                if ($open !== null) {
                    throw new DeactivationTooSoonException($memberId, open: $open);
                }
            }
            $this->members->setActive($memberId, $active);

            return $member;
        });
    }
}
