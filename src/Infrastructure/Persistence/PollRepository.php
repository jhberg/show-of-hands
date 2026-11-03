<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Majority;
use ShowOfHands\Domain\MajorityBase;
use ShowOfHands\Domain\MajorityShare;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\PollDetails;
use ShowOfHands\Domain\PollEditing;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\Tally;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Polls without their ballots. A closed poll carries the tally frozen at its close.
 */
final readonly class PollRepository
{
    private const COLUMNS = 'id, kind, subject_name, question, voice_group_key, date, note, status, closed_at, eligible, yes_weight, no_weight, delegation_count, majority, majority_base, opened_at, reopened_at, reopen_count';

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
     * Every poll for the admin area: open ones first, then drafts, then closed ones, each by date,
     * the latest first. Polls without a date come last in their group.
     *
     * @return list<Poll>
     */
    public function all(): array
    {
        return $this->select(
            "SELECT " . self::COLUMNS . " FROM polls
             ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, date IS NULL, date DESC, id DESC",
        );
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
            self::utc($closedAt),
            $tally->eligible,
            $tally->yes,
            $tally->no,
            $tally->cast(),
            $tally->delegations,
            $id,
        ]);
    }

    /**
     * Stores a new poll and returns its id. The poll takes the majority the settings name now.
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
            "INSERT INTO polls (kind, subject_name, question, voice_group_key, date, note, status, created_at, opened_at, majority, majority_base)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                 (SELECT value FROM settings WHERE key = 'majority'), (SELECT value FROM settings WHERE key = 'majority_base'))",
        )->execute([$kind->value, $subjectName, $question, $voiceGroupKey, $date, $note, $status->value, $createdAt, $openedAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Stores the admin's new poll as a draft and returns its id.
     */
    public function create(PollDetails $details, \DateTimeImmutable $createdAt): int
    {
        return $this->add($details->kind, $details->subjectName, $details->question, $details->voiceGroupKey, $details->date, $details->note, PollStatus::Draft, self::utc($createdAt));
    }

    /**
     * Saves what the poll's status still lets an admin change, and nothing more. The status is
     * re-read inside the transaction, so a draft published in the meantime keeps its kind, name,
     * question, and voice group, and a poll closed in the meantime stays as it was counted.
     *
     * @return PollEditing|null what the save could change, or null when the poll no longer exists
     */
    public function update(int $id, PollDetails $details): ?PollEditing
    {
        return Transaction::immediate($this->pdo, function () use ($id, $details): ?PollEditing {
            $editing = $this->find($id)?->editing();
            if ($editing === PollEditing::All) {
                $this->pdo->prepare('UPDATE polls SET kind = ?, subject_name = ?, question = ?, voice_group_key = ?, date = ?, note = ? WHERE id = ?')
                    ->execute([$details->kind->value, $details->subjectName, $details->question, $details->voiceGroupKey, $details->date, $details->note, $id]);
            } elseif ($editing === PollEditing::NoteAndDate) {
                $this->pdo->prepare('UPDATE polls SET date = ?, note = ? WHERE id = ?')->execute([$details->date, $details->note, $id]);
            }

            return $editing;
        });
    }

    /**
     * Publishes a draft, so members can vote. One conditional UPDATE, so a poll is published once.
     *
     * @return bool false when the poll is no draft (any more)
     */
    public function publish(int $id, \DateTimeImmutable $openedAt): bool
    {
        $statement = $this->pdo->prepare('UPDATE polls SET status = ?, opened_at = ? WHERE id = ? AND status = ?');
        $statement->execute([PollStatus::Open->value, self::utc($openedAt), $id, PollStatus::Draft->value]);

        return $statement->rowCount() === 1;
    }

    /**
     * Deletes a draft. Published polls are never deleted by hand, only by retention once closed.
     *
     * @return bool false when the poll is no draft (any more)
     */
    public function deleteDraft(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM polls WHERE id = ? AND status = ?');
        $statement->execute([$id, PollStatus::Draft->value]);

        return $statement->rowCount() === 1;
    }

    /**
     * Opens the closed poll again and clears its frozen tally. The caller runs it inside the
     * reopen's transaction, after checking that the poll may reopen.
     */
    public function markReopened(int $id, \DateTimeImmutable $reopenedAt): void
    {
        $this->pdo->prepare(
            'UPDATE polls SET status = ?, closed_at = NULL, eligible = NULL, yes_weight = NULL, no_weight = NULL, cast_weight = NULL, delegation_count = NULL,
                 reopened_at = ?, reopen_count = reopen_count + 1
             WHERE id = ?',
        )->execute([PollStatus::Open->value, self::utc($reopenedAt), $id]);
    }

    /**
     * Deletes the closed polls whose retention has run out, with their ballots, turnout,
     * delegations, and turnout snapshot. Nothing of them remains, not even the name.
     *
     * @return int the number of polls deleted
     */
    public function purgeExpired(\DateTimeImmutable $now, int $retentionDays): int
    {
        $statement = $this->pdo->prepare('DELETE FROM polls WHERE status = ? AND closed_at <= ?');
        $statement->execute([PollStatus::Closed->value, self::utc($now->modify("-{$retentionDays} days"))]);

        return $statement->rowCount();
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
        return $this->select('SELECT ' . self::COLUMNS . ' FROM polls WHERE status = ? ORDER BY ' . $orderBy, [$status->value]);
    }

    /**
     * @param list<string> $params
     *
     * @return list<Poll>
     */
    private function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

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
        $majority = new Majority(MajorityShare::from(Row::string($row, 'majority')), MajorityBase::from(Row::string($row, 'majority_base')));

        return new Poll(
            id: Row::int($row, 'id'),
            kind: PollKind::from(Row::string($row, 'kind')),
            subjectName: Row::string($row, 'subject_name'),
            question: Row::nullableString($row, 'question'),
            voiceGroupKey: Row::string($row, 'voice_group_key'),
            date: Row::nullableString($row, 'date'),
            note: Row::string($row, 'note'),
            status: $status,
            majority: $majority,
            closedAt: $status === PollStatus::Closed ? Row::nullableString($row, 'closed_at') : null,
            tally: $status === PollStatus::Closed && $row['eligible'] !== null ? new Tally(
                Row::int($row, 'eligible'),
                Row::int($row, 'yes_weight'),
                Row::int($row, 'no_weight'),
                Row::int($row, 'delegation_count'),
                $majority,
            ) : null,
            openedAt: Row::nullableString($row, 'opened_at'),
            reopenedAt: Row::nullableString($row, 'reopened_at'),
            reopenCount: Row::int($row, 'reopen_count'),
        );
    }

    /** A moment as stored: UTC ISO 8601, which also sorts and compares as text. */
    private static function utc(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
