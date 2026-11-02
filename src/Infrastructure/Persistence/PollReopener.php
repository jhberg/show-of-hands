<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\ReopenRefusal;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Reopens a poll closed by mistake, in one BEGIN IMMEDIATE transaction: it clears the frozen tally
 * and the turnout snapshot, so the poll counts afresh at its next close. Ballots, turnout, and
 * delegations stayed in place since the close, because the ballots are deleted only once the close
 * day has ended, and from then on the poll no longer reopens.
 */
final readonly class PollReopener
{
    public function __construct(
        private \PDO $pdo,
        private PollRepository $polls,
        private RollRepository $roll,
    ) {}

    /**
     * @return ReopenRefusal|null why the poll did not reopen, or null when it did
     */
    public function reopen(int $pollId, \DateTimeImmutable $now, \DateTimeZone $zone): ?ReopenRefusal
    {
        return Transaction::immediate($this->pdo, function () use ($pollId, $now, $zone): ?ReopenRefusal {
            $poll = $this->polls->find($pollId);
            $refusal = $poll === null ? ReopenRefusal::NotClosed : $poll->reopenRefusal($now, $zone);
            if ($refusal === null) {
                $this->polls->markReopened($pollId, $now);
                $this->roll->delete($pollId);
            }

            return $refusal;
        });
    }
}
