<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Member;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Removes a member from the member list, which is also the access list, in one BEGIN IMMEDIATE
 * transaction. The foreign keys take the delegations to and from them in closed polls. Closed
 * polls keep their frozen tally and their turnout snapshot.
 *
 * A member who voted, delegated, or received a vote in an open poll stays until it closes:
 * removing them would take a cast vote out of the count, so an admin could remove votes they
 * expect to dislike. After a poll closed today, they stay until the next day, since an admin may
 * still reopen it and its recount without them would reveal their choice.
 */
final readonly class MemberRemover
{
    public function __construct(
        private \PDO $pdo,
        private MemberRepository $members,
    ) {}

    /**
     * @return Member|null the removed member, or null when they no longer existed
     *
     * @throws RemovalTooSoonException
     */
    public function remove(int $memberId, \DateTimeImmutable $now, \DateTimeZone $zone): ?Member
    {
        return Transaction::immediate($this->pdo, function () use ($memberId, $now, $zone): ?Member {
            $member = $this->members->find($memberId);
            if ($member === null) {
                return null;
            }
            $open = PollsTakenPart::hold($this->pdo, $memberId, $now, $zone);
            if ($open !== null) {
                throw new RemovalTooSoonException($memberId, open: $open);
            }
            $this->members->delete($memberId);

            return $member;
        });
    }
}
