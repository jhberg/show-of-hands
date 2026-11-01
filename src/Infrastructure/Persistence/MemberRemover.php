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
            foreach ($this->countedPollsTakenPartIn($memberId) as $closedAt) {
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

    /**
     * The polls counted once already, closed or reopened, in which the member voted, delegated, or
     * received a vote: when each closed, or null for a reopened poll that is open again. Turnout
     * and delegations tell this; the ballots are not read.
     *
     * @return list<string|null>
     */
    private function countedPollsTakenPartIn(int $memberId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT CASE WHEN status = ? THEN closed_at END AS closed_at FROM polls p
             WHERE (status = ? OR (status = ? AND reopen_count > 0)) AND (
                EXISTS (SELECT 1 FROM participation WHERE poll_id = p.id AND member_id = ?)
                OR EXISTS (SELECT 1 FROM delegations WHERE poll_id = p.id AND (from_member_id = ? OR to_member_id = ?))
            )',
        );
        $statement->execute([PollStatus::Closed->value, PollStatus::Closed->value, PollStatus::Open->value, $memberId, $memberId, $memberId]);
        $polls = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $polls[] = Row::nullableString($row, 'closed_at');
        }

        return $polls;
    }
}
