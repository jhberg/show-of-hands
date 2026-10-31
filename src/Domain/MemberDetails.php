<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The name and sign-in addresses an admin enters for a member. A member needs a name and at
 * least one valid address. The second address is optional and must differ from the first.
 */
final readonly class MemberDetails
{
    /**
     * @param list<string> $emails normalized, the first one first
     */
    private function __construct(
        public string $name,
        public array $emails,
    ) {}

    /**
     * An empty first field with a filled second one makes the second address the first.
     */
    public static function parse(string $name, string $email, string $secondEmail): self|MemberDetailsError
    {
        $name = trim($name);
        if ($name === '') {
            return MemberDetailsError::NameMissing;
        }
        $emails = [];
        foreach ([[$email, MemberDetailsError::FirstAddressInvalid], [$secondEmail, MemberDetailsError::SecondAddressInvalid]] as [$field, $invalid]) {
            if (trim($field) === '') {
                continue;
            }
            $normalized = EmailAddress::normalize($field);
            if ($normalized === null) {
                return $invalid;
            }
            $emails[] = $normalized;
        }
        if ($emails === []) {
            return MemberDetailsError::AddressMissing;
        }
        if (count($emails) === 2 && $emails[0] === $emails[1]) {
            return MemberDetailsError::SameAddresses;
        }

        return new self($name, $emails);
    }
}
