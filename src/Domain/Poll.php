<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * One question to vote on, with its own link.
 */
final readonly class Poll
{
    /**
     * @param string|null $question the admin's question, only for exception polls
     * @param string|null $date the audition or decision date as Y-m-d, null while it is open
     * @param string|null $closedAt when the poll closed, as UTC ISO 8601, only while it is closed
     * @param Majority $majority the rule in force when the poll was created
     * @param Tally|null $tally the count frozen at the close, only while the poll is closed
     * @param string|null $openedAt when an admin published it, as UTC ISO 8601
     * @param string|null $reopenedAt when an admin last reopened it, as UTC ISO 8601
     * @param int $reopenCount how often an admin reopened it
     */
    public function __construct(
        public int $id,
        public PollKind $kind,
        public string $subjectName,
        public ?string $question,
        public string $voiceGroupKey,
        public ?string $date,
        public string $note,
        public PollStatus $status,
        public Majority $majority,
        public ?string $closedAt = null,
        public ?Tally $tally = null,
        public ?string $openedAt = null,
        public ?string $reopenedAt = null,
        public int $reopenCount = 0,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === PollStatus::Open;
    }

    /** Members see a poll once it is published. A draft looks like a poll that does not exist. */
    public function isPublished(): bool
    {
        return !$this->isDraft();
    }

    /** Whether an admin reopened the poll and it is open again, so every member sees the notice. */
    public function isReopened(): bool
    {
        return $this->isOpen() && $this->reopenedAt !== null;
    }

    /** Whether the poll is a draft that members do not see yet. */
    public function isDraft(): bool
    {
        return $this->status === PollStatus::Draft;
    }

    /** The day retention deletes the closed poll, as Y-m-d in the choir's time zone, or null while it is not closed. */
    public function deletionDay(int $retentionDays, \DateTimeZone $zone): ?string
    {
        if ($this->closedAt === null) {
            return null;
        }

        return (new \DateTimeImmutable($this->closedAt))->modify("+{$retentionDays} days")->setTimezone($zone)->format('Y-m-d');
    }

    /**
     * Why an admin may not reopen the poll now, or null when they may. A closed poll reopens only
     * on the day it closed, in the choir's time zone: its ballots are deleted once that day ends,
     * and from then on the result is final.
     */
    public function reopenRefusal(\DateTimeImmutable $now, \DateTimeZone $zone): ?ReopenRefusal
    {
        if ($this->status !== PollStatus::Closed || $this->closedAt === null) {
            return ReopenRefusal::NotClosed;
        }

        return CloseDay::hasEnded(new \DateTimeImmutable($this->closedAt), $now, $zone) ? ReopenRefusal::TooLate : null;
    }

    /**
     * What an admin may edit: a draft fully, an open poll only its note and date, because members
     * already vote on its kind, name, question, and voice group. A closed poll stays as it was counted.
     */
    public function editing(): PollEditing
    {
        return match ($this->status) {
            PollStatus::Draft => PollEditing::All,
            PollStatus::Open => PollEditing::NoteAndDate,
            PollStatus::Closed => PollEditing::Nothing,
        };
    }

    /**
     * Whether the head of the poll page repeats the exception question. While the poll is
     * open, the ballot asks it, so the head shows it only once the result replaced the ballot.
     */
    public function showsQuestionInHead(): bool
    {
        return $this->kind === PollKind::Exception && $this->status === PollStatus::Closed;
    }
}
