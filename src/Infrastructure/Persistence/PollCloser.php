<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Turnout;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Closes a poll: counts it, freezes the tally, and takes the turnout snapshot, all in one
 * BEGIN IMMEDIATE transaction. No ballot or delegation can land between the count and the close.
 */
final readonly class PollCloser
{
    public function __construct(
        private \PDO $pdo,
        private PollRepository $polls,
        private BallotRepository $ballots,
        private DelegationRepository $delegations,
        private MemberRepository $members,
        private RollRepository $roll,
    ) {}

    /**
     * @throws PollNotOpenException
     */
    public function close(int $pollId, \DateTimeImmutable $at): void
    {
        Transaction::immediate($this->pdo, function () use ($pollId, $at): void {
            OpenPoll::require($this->pdo, $pollId);
            // The order is irrelevant here: the snapshot is sorted when it is read.
            $members = $this->members->all('root');
            $turnout = Turnout::of($members, $this->ballots->participantIds($pollId), $this->delegations->forPoll($pollId));
            $received = [];
            foreach ($turnout->delegations as $entry) {
                if ($entry['to_voted']) {
                    $received[$entry['to']->id] = ($received[$entry['to']->id] ?? 0) + 1;
                }
            }
            $this->polls->markClosed($pollId, $at, $this->ballots->tally($pollId, $turnout->eligible, $received));
            $this->roll->write($pollId, $members, $turnout);
        });
    }
}
