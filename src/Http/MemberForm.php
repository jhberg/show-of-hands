<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use ShowOfHands\Domain\EmailAddress;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\MemberDetailsError;

/**
 * The inline form that adds or edits a member, with the values as entered and, after a
 * refused save, the field the message belongs to.
 */
final readonly class MemberForm
{
    /**
     * @param int|null $memberId the member being edited, null for a new one
     * @param string|null $group the voice group key, null for a member without a group
     * @param string|null $errorField name, email, or email2
     * @param string|null $errorKey the message key for that field, also shown as the toast
     * @param array<string, string> $errorParams the message's placeholders
     */
    public function __construct(
        public ?int $memberId,
        public string $name,
        public string $email,
        public string $email2,
        public ?string $group,
        public bool $board,
        public bool $admin,
        public string $displayName,
        public ?string $errorField = null,
        public ?string $errorKey = null,
        public array $errorParams = [],
    ) {}

    /** An empty form for a new member, in the first voice group. */
    public static function blank(?string $firstGroup): self
    {
        return new self(null, '', '', '', $firstGroup, false, false, '');
    }

    /**
     * @param list<string> $emails
     */
    public static function of(Member $member, array $emails): self
    {
        return new self($member->id, $member->name, $emails[0] ?? '', $emails[1] ?? '', $member->voiceGroupKey, $member->isBoard, $member->isAdmin, $member->displayName ?? '');
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromBody(array $body, ?int $memberId): self
    {
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $group = $text('group');

        return new self(
            $memberId,
            $text('name'),
            $text('email'),
            $text('email2'),
            $group === '' ? null : $group,
            $text('board') === '1',
            $text('admin') === '1',
            $text('display_name'),
        );
    }

    /** The form with the message at the field it belongs to. */
    public function refused(MemberDetailsError $error): self
    {
        $field = match ($error) {
            MemberDetailsError::NameMissing => 'name',
            MemberDetailsError::AddressMissing, MemberDetailsError::FirstAddressInvalid => 'email',
            MemberDetailsError::SecondAddressInvalid, MemberDetailsError::SameAddresses => 'email2',
        };

        return $this->withError($field, $error === MemberDetailsError::SameAddresses ? 'toast.member.same' : 'toast.member.invalid');
    }

    /** The admin's own form without the address they signed in with. */
    public function withoutOwnAddress(): self
    {
        return $this->withError('email', 'toast.member.own_address');
    }

    /** The form with the message at the field that holds the address another member uses. */
    public function taken(string $email, string $ownerName): self
    {
        return $this->withError(EmailAddress::normalize($this->email) === $email ? 'email' : 'email2', 'toast.member.taken', ['name' => $ownerName]);
    }

    /**
     * @param array<string, string> $params
     */
    private function withError(string $field, string $messageKey, array $params = []): self
    {
        return new self($this->memberId, $this->name, $this->email, $this->email2, $this->group, $this->board, $this->admin, $this->displayName, $field, $messageKey, $params);
    }
}
