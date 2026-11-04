<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\CloseDay;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Removes a member from the member list, which is also the access list, in one BEGIN IMMEDIATE
 * transaction. Their ballot and turnout mark go from the open polls, the delegations to and from
 * them from every poll. Closed polls keep their frozen tally and their turnout snapshot.
 *
 * A member who voted or delegated in a poll closed today stays until the next day. An admin may
 * still reopen that poll, and its recount without them would reveal their choice. For the same
 * reason, a member who took part in a reopened poll stays while it is open again.
 */
final readonly class MemberRemover
{
    public function __construct(
        private \PDO $pdo,
        private MemberRepository $members,
        private BallotRepository $ballots,
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
            foreach (PollsTakenPart::closings($this->pdo, $memberId, neverClosed: false) as $closedAt) {
                if ($closedAt === null) {
                    throw new RemovalTooSoonException($memberId, reopened: true);
                }
                if (!CloseDay::hasEnded(new \DateTimeImmutable($closedAt), $now, $zone)) {
                    throw new RemovalTooSoonException($memberId, reopened: false);
                }
            }
            $polls = $this->pdo->prepare('SELECT id FROM polls WHERE status = ?');
            $polls->execute([PollStatus::Open->value]);
            foreach ($polls->fetchAll() as $row) {
                assert(is_array($row));
                $this->ballots->withdraw(Row::int($row, 'id'), $memberId);
            }
            $this->members->delete($memberId);

            return $member;
        });
    }
}
