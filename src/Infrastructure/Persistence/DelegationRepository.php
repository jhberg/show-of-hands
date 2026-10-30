<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\DelegationRefusal;
use ShowOfHands\Domain\Delegations;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Vote delegations. Every write checks the rules inside one BEGIN IMMEDIATE transaction, so two
 * members delegating at the same moment can never build a chain or pass the limit.
 */
final readonly class DelegationRepository
{
    public function __construct(
        private \PDO $pdo,
        private BallotRepository $ballots,
        private SettingsRepository $settings,
    ) {}

    /**
     * Hands $from's vote in the poll to $to and withdraws $from's own ballot. Returns why it
     * cannot, or null once it is done.
     *
     * @throws PollNotOpenException
     */
    public function delegate(int $pollId, int $from, int $to): ?DelegationRefusal
    {
        return Transaction::immediate($this->pdo, function () use ($pollId, $from, $to): ?DelegationRefusal {
            OpenPoll::require($this->pdo, $pollId);
            $member = $this->pdo->prepare('SELECT 1 FROM members WHERE id = ?');
            $member->execute([$to]);
            if ($member->fetchColumn() === false) {
                return DelegationRefusal::UnknownTarget;
            }
            $refusal = $this->forPoll($pollId)->refusal($from, $to, $this->settings->delegationEnabled(), $this->settings->maxReceived());
            if ($refusal !== null) {
                return $refusal;
            }
            $this->ballots->withdraw($pollId, $from);
            $this->pdo->prepare('INSERT INTO delegations (poll_id, from_member_id, to_member_id) VALUES (?, ?, ?)')
                ->execute([$pollId, $from, $to]);

            return null;
        });
    }

    /**
     * Takes $from's delegation back without a ballot. Returns whether there was one. Allowed
     * also while delegation is switched off, since it only ends a delegation.
     *
     * @throws PollNotOpenException
     */
    public function revoke(int $pollId, int $from): bool
    {
        return Transaction::immediate($this->pdo, function () use ($pollId, $from): bool {
            OpenPoll::require($this->pdo, $pollId);
            $delete = $this->pdo->prepare('DELETE FROM delegations WHERE poll_id = ? AND from_member_id = ?');
            $delete->execute([$pollId, $from]);

            return $delete->rowCount() === 1;
        });
    }

    public function forPoll(int $pollId): Delegations
    {
        $statement = $this->pdo->prepare('SELECT from_member_id, to_member_id FROM delegations WHERE poll_id = ?');
        $statement->execute([$pollId]);
        $recipients = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $recipients[Row::int($row, 'from_member_id')] = Row::int($row, 'to_member_id');
        }

        return new Delegations($recipients);
    }

    /**
     * Whom the member handed their vote to, in those of the given polls where they did.
     *
     * @param list<int> $pollIds
     *
     * @return array<int, int> the recipient's member id by poll id
     */
    public function recipientsOf(int $memberId, array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($pollIds), '?'));
        $statement = $this->pdo->prepare("SELECT poll_id, to_member_id FROM delegations WHERE from_member_id = ? AND poll_id IN ({$placeholders})");
        $statement->execute([$memberId, ...$pollIds]);
        $recipients = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $recipients[Row::int($row, 'poll_id')] = Row::int($row, 'to_member_id');
        }

        return $recipients;
    }
}
