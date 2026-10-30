<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\CastOutcome;
use ShowOfHands\Domain\CastResult;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * The secret ballots and the turnout. While a poll is open, the only read of the ballots table
 * is the member's own row, found by their token. Turnout reads the participation table alone.
 * Casting a ballot also ends the member's delegation.
 */
final readonly class BallotRepository
{
    public function __construct(
        private \PDO $pdo,
        private BallotToken $tokens,
    ) {}

    /**
     * Records the member's choice and marks them as having voted. A member who had handed their
     * vote on takes it back by voting, which ends the delegation. The poll status is re-read
     * inside the transaction, so a ballot never commits after the close that froze the tally.
     *
     * @throws PollNotOpenException
     */
    public function cast(int $pollId, int $memberId, Choice $choice): CastResult
    {
        return Transaction::immediate($this->pdo, function () use ($pollId, $memberId, $choice): CastResult {
            OpenPoll::require($this->pdo, $pollId);
            $reclaimedFrom = $this->endDelegation($pollId, $memberId);
            $token = $this->tokens->for($pollId, $memberId);
            $outcome = CastOutcome::of($this->choiceByToken($pollId, $token), $choice);
            if ($outcome !== CastOutcome::Unchanged) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO ballots (poll_id, token, choice) VALUES (?, ?, ?)
                     ON CONFLICT (poll_id, token) DO UPDATE SET choice = excluded.choice',
                );
                $insert->bindValue(1, $pollId, \PDO::PARAM_INT);
                $insert->bindValue(2, $token, \PDO::PARAM_LOB);
                $insert->bindValue(3, $choice->value);
                $insert->execute();
                $this->pdo->prepare('INSERT OR IGNORE INTO participation (poll_id, member_id) VALUES (?, ?)')
                    ->execute([$pollId, $memberId]);
            }

            return new CastResult($outcome, $reclaimedFrom);
        });
    }

    /**
     * Withdraws the member's ballot and their turnout mark, for a member who hands their vote on.
     * The caller runs it inside the delegation's transaction.
     */
    public function withdraw(int $pollId, int $memberId): void
    {
        $delete = $this->pdo->prepare('DELETE FROM ballots WHERE poll_id = ? AND token = ?');
        $delete->bindValue(1, $pollId, \PDO::PARAM_INT);
        $delete->bindValue(2, $this->tokens->for($pollId, $memberId), \PDO::PARAM_LOB);
        $delete->execute();
        $this->pdo->prepare('DELETE FROM participation WHERE poll_id = ? AND member_id = ?')->execute([$pollId, $memberId]);
    }

    /** The member's own choice, or null before they voted. */
    public function choice(int $pollId, int $memberId): ?Choice
    {
        return $this->choiceByToken($pollId, $this->tokens->for($pollId, $memberId));
    }

    /**
     * The members who have voted in the poll.
     *
     * @return list<int>
     */
    public function participantIds(int $pollId): array
    {
        $statement = $this->pdo->prepare('SELECT member_id AS id FROM participation WHERE poll_id = ?');
        $statement->execute([$pollId]);

        return self::ids($statement);
    }

    /**
     * The polls, among those given, in which the member has voted.
     *
     * @param list<int> $pollIds
     *
     * @return list<int>
     */
    public function pollsVotedIn(int $memberId, array $pollIds): array
    {
        if ($pollIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($pollIds), '?'));
        $statement = $this->pdo->prepare("SELECT poll_id AS id FROM participation WHERE member_id = ? AND poll_id IN ({$placeholders})");
        $statement->execute([$memberId, ...$pollIds]);

        return self::ids($statement);
    }

    /**
     * Ends the member's delegation in the poll and returns the member who held the vote, or null.
     */
    private function endDelegation(int $pollId, int $memberId): ?int
    {
        $recipient = $this->pdo->prepare('SELECT to_member_id FROM delegations WHERE poll_id = ? AND from_member_id = ?');
        $recipient->execute([$pollId, $memberId]);
        $to = $recipient->fetchColumn();
        if ($to === false) {
            return null;
        }
        $this->pdo->prepare('DELETE FROM delegations WHERE poll_id = ? AND from_member_id = ?')->execute([$pollId, $memberId]);

        return (int) $to;
    }

    /** The token binds as a BLOB, the column's type. As text it would never equal the stored value. */
    private function choiceByToken(int $pollId, string $token): ?Choice
    {
        $statement = $this->pdo->prepare('SELECT choice FROM ballots WHERE poll_id = ? AND token = ?');
        $statement->bindValue(1, $pollId, \PDO::PARAM_INT);
        $statement->bindValue(2, $token, \PDO::PARAM_LOB);
        $statement->execute();
        $choice = $statement->fetchColumn();

        return is_string($choice) ? Choice::from($choice) : null;
    }

    /**
     * @return list<int>
     */
    private static function ids(\PDOStatement $statement): array
    {
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $ids[] = Row::int($row, 'id');
        }

        return $ids;
    }
}
