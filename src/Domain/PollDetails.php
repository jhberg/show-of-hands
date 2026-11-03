<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * What an admin enters for a poll. Every poll needs the name of the person it is about, and a
 * probation exception also needs the question. An admission asks its fixed question, so it keeps none.
 */
final readonly class PollDetails
{
    /**
     * @param string|null $question only for exception polls
     * @param string|null $date the audition or decision date as Y-m-d, or null while it is open
     */
    private function __construct(
        public PollKind $kind,
        public string $subjectName,
        public ?string $question,
        public string $voiceGroupKey,
        public ?string $date,
        public string $note,
    ) {}

    /**
     * @param string $date Y-m-d, or empty while the date is open
     *
     * @throws \InvalidArgumentException for a date that is not a day, which the date field never sends
     */
    public static function parse(PollKind $kind, string $subjectName, string $question, string $voiceGroupKey, string $date, string $note): self|PollDetailsError
    {
        $subjectName = trim($subjectName);
        if ($subjectName === '') {
            return PollDetailsError::NameMissing;
        }
        $question = trim($question);
        if ($kind === PollKind::Exception && $question === '') {
            return PollDetailsError::QuestionMissing;
        }

        return new self(
            $kind,
            $subjectName,
            $kind === PollKind::Exception ? $question : null,
            $voiceGroupKey,
            self::day($date),
            trim($note),
        );
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function day(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date '{$date}'.");
        }

        return $date;
    }
}
