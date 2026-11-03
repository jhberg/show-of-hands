<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\PollDetailsError;
use ShowOfHands\Domain\PollEditing;
use ShowOfHands\Domain\PollKind;

/**
 * The form that creates or edits a poll, with the values as entered and, after a refused save,
 * the field the message belongs to. The kind it shows sets its labels and whether it asks the question.
 */
final readonly class PollForm
{
    /**
     * @param Poll|null $poll the poll being edited, null for a new one
     * @param PollEditing $editing what the form offers: everything, or only note and date
     * @param string|null $errorField subject_name or question
     * @param string|null $errorKey the message key for that field, also shown as the toast
     */
    public function __construct(
        public ?Poll $poll,
        public PollEditing $editing,
        public PollKind $kind,
        public string $subjectName,
        public string $question,
        public string $group,
        public string $date,
        public string $note,
        public ?string $errorField = null,
        public ?string $errorKey = null,
    ) {}

    /** An empty form for a new admission, in the first voice group. */
    public static function blank(string $firstGroup): self
    {
        return new self(null, PollEditing::All, PollKind::Admission, '', '', $firstGroup, '', '');
    }

    public static function of(Poll $poll): self
    {
        return new self($poll, $poll->editing(), $poll->kind, $poll->subjectName, $poll->question ?? '', $poll->voiceGroupKey, $poll->date ?? '', $poll->note);
    }

    /**
     * The values a save sent. A form that offers only note and date keeps the poll's other values.
     *
     * @param array<mixed> $body
     * @param PollKind $kind the kind the body names, or admission when it names none; the caller refuses a missing kind for a full form
     */
    public static function fromBody(array $body, PollKind $kind, ?Poll $poll): self
    {
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $editing = $poll?->editing() ?? PollEditing::All;
        if ($poll !== null && $editing !== PollEditing::All) {
            return new self($poll, $editing, $poll->kind, $poll->subjectName, $poll->question ?? '', $poll->voiceGroupKey, $text('date'), $text('note'));
        }

        return new self($poll, $editing, $kind, $text('subject_name'), $text('question'), $text('group'), $text('date'), $text('note'));
    }

    /** The form with the message at the field it belongs to. */
    public function refused(PollDetailsError $error): self
    {
        [$field, $key] = match ($error) {
            PollDetailsError::NameMissing => ['subject_name', 'toast.poll.name_missing'],
            PollDetailsError::QuestionMissing => ['question', 'toast.poll.question_missing'],
        };

        return new self($this->poll, $this->editing, $this->kind, $this->subjectName, $this->question, $this->group, $this->date, $this->note, $field, $key);
    }

    /** Whether the form offers kind, name, question, and voice group. */
    public function editsAll(): bool
    {
        return $this->editing === PollEditing::All;
    }

    /** Where the form posts to, and where Back and Cancel lead: the list, or the poll's detail. */
    public function url(): string
    {
        return $this->poll === null ? AdminPollsPage::PATH : AdminPollsPage::path($this->poll->id);
    }
}
