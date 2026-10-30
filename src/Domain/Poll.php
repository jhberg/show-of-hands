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
    ) {}

    public function isOpen(): bool
    {
        return $this->status === PollStatus::Open;
    }

    /** Members see a poll once it is published. A draft looks like a poll that does not exist. */
    public function isPublished(): bool
    {
        return $this->status !== PollStatus::Draft;
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
