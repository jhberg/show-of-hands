<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * A person on the member list. The list is also the access list: only members can sign in.
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
    ) {}

    public function firstName(): string
    {
        return self::words($this->name)[0] ?? $this->name;
    }

    /** Up to two capital letters, the first letter of the first two words, for the avatar. */
    public function initials(): string
    {
        $letters = array_map(static fn(string $word): string => mb_substr($word, 0, 1), self::words($this->name));

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
