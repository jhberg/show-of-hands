<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * A person on the member list. The list is also the access list: only members can sign in. An
 * inactive member keeps signing in, but is no eligible voter until they are active again.
 *
 * Name lists show the shorter display name where a member has one, the full name otherwise. The
 * admin area's member list keeps the full name.
 */
final readonly class Member
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $voiceGroupKey,
        public bool $isBoard,
        public bool $isAdmin,
        public ?string $locale = null,
        public bool $isActive = true,
        public ?string $displayName = null,
        public ?ColorMode $colorMode = null,
    ) {}

    /** The display name, or the full name without one. */
    public function shownName(): string
    {
        return $this->displayName ?? $this->name;
    }

    /**
     * The eligible voters among the given members: the active ones, in the given order.
     *
     * @param list<Member> $members
     *
     * @return list<Member>
     */
    public static function eligibleOf(array $members): array
    {
        return array_values(array_filter($members, static fn(self $member): bool => $member->isActive));
    }

    /** The shown name's first word. */
    public function firstName(): string
    {
        return self::words($this->shownName())[0] ?? $this->shownName();
    }

    /** Up to two capital letters, the first letter of the shown name's first two words, for the avatar. */
    public function initials(): string
    {
        $letters = array_map(static fn(string $word): string => mb_substr($word, 0, 1), self::words($this->shownName()));

        return mb_strtoupper(mb_substr(implode('', $letters), 0, 2));
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        return preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
