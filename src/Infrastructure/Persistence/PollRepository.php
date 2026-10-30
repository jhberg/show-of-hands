<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\Tally;

/**
 * Polls without their ballots. A closed poll carries the tally frozen at its close.
 */
final readonly class PollRepository
{
    private const COLUMNS = 'id, kind, subject_name, question, voice_group_key, date, note, status, closed_at, eligible, yes_weight, no_weight, delegation_count';

    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?Poll
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM polls WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? self::poll($row) : null;
    }

    /**
     * The poll as members may see it: a draft looks like a poll that does not exist.
     */
    public function findPublished(int $id): ?Poll
    {
        $poll = $this->find($id);

        return $poll !== null && $poll->isPublished() ? $poll : null;
    }

    /**
     * The open polls, by date, then in the order they were created. Polls without a date come first.
     *
     * @return list<Poll>
     */
    public function open(): array
    {
        return $this->byStatus(PollStatus::Open, "coalesce(date, ''), id");
    }

    /**
     * The closed polls, the most recently closed first.
     *
     * @return list<Poll>
     */
    public function closed(): array
    {
        return $this->byStatus(PollStatus::Closed, 'closed_at DESC, id DESC');
    }

    /**
     * Closes the poll with its frozen tally. The caller runs it inside the close's transaction,
     * after checking that the poll is open.
     */
    public function markClosed(int $id, \DateTimeImmutable $closedAt, Tally $tally): void
    {
        $this->pdo->prepare(
            'UPDATE polls SET status = ?, closed_at = ?, eligible = ?, yes_weight = ?, no_weight = ?, cast_weight = ?, delegation_count = ?
             WHERE id = ?',
        )->execute([
            PollStatus::Closed->value,
            $closedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            $tally->eligible,
            $tally->yes,
            $tally->no,
            $tally->cast(),
            $tally->delegations,
            $id,
        ]);
    }

    /**
     * Stores a new poll and returns its id.
     */
    public function add(
        PollKind $kind,
        string $subjectName,
        ?string $question,
        string $voiceGroupKey,
        ?string $date,
        string $note,
        PollStatus $status,
        string $createdAt,
        ?string $openedAt = null,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO polls (kind, subject_name, question, voice_group_key, date, note, status, created_at, opened_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$kind->value, $subjectName, $question, $voiceGroupKey, $date, $note, $status->value, $createdAt, $openedAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Deletes every poll with its ballots and turnout, and resets the id counter. Only the demo seed does this.
     */
    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM polls');
        $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'polls'");
    }

    /**
     * @return list<Poll>
     */
    private function byStatus(PollStatus $status, string $orderBy): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM polls WHERE status = ? ORDER BY ' . $orderBy);
        $statement->execute([$status->value]);

        $polls = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $polls[] = self::poll($row);
        }

        return $polls;
    }

    /**
     * @param array<mixed> $row
     */
    private static function poll(array $row): Poll
    {
        $status = PollStatus::from(Row::string($row, 'status'));

        return new Poll(
            id: Row::int($row, 'id'),
            kind: PollKind::from(Row::string($row, 'kind')),
            subjectName: Row::string($row, 'subject_name'),
            question: Row::nullableString($row, 'question'),
            voiceGroupKey: Row::string($row, 'voice_group_key'),
            date: Row::nullableString($row, 'date'),
            note: Row::string($row, 'note'),
            status: $status,
            closedAt: $status === PollStatus::Closed ? Row::nullableString($row, 'closed_at') : null,
            tally: $status === PollStatus::Closed && $row['eligible'] !== null ? new Tally(
                Row::int($row, 'eligible'),
                Row::int($row, 'yes_weight'),
                Row::int($row, 'no_weight'),
                Row::int($row, 'delegation_count'),
            ) : null,
        );
    }
}
