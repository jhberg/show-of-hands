<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;

/**
 * Polls without their ballots.
 */
final readonly class PollRepository
{
    private const COLUMNS = 'id, kind, subject_name, question, voice_group_key, date, note, status';

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
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM polls WHERE status = ? ORDER BY coalesce(date, ''), id");
        $statement->execute([PollStatus::Open->value]);

        $polls = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $polls[] = self::poll($row);
        }

        return $polls;
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
     * @param array<mixed> $row
     */
    private static function poll(array $row): Poll
    {
        return new Poll(
            id: Row::int($row, 'id'),
            kind: PollKind::from(Row::string($row, 'kind')),
            subjectName: Row::string($row, 'subject_name'),
            question: Row::nullableString($row, 'question'),
            voiceGroupKey: Row::string($row, 'voice_group_key'),
            date: Row::nullableString($row, 'date'),
            note: Row::string($row, 'note'),
            status: PollStatus::from(Row::string($row, 'status')),
        );
    }
}
