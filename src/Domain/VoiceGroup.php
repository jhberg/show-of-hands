<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * A voice group such as S1 or A2. The key is fixed because it appears in URLs; admins edit the label.
 */
final readonly class VoiceGroup
{
    public const KEY_PATTERN = '/^[A-Z0-9]{1,4}$/';

    public function __construct(
        public string $key,
        public string $label,
    ) {}

    /**
     * The label of the group with this key, or the key when the group no longer exists.
     *
     * @param list<self> $groups
     */
    public static function labelOf(array $groups, string $key): string
    {
        foreach ($groups as $group) {
            if ($group->key === $key) {
                return $group->label;
            }
        }

        return $key;
    }

    /**
     * The groups a new installation starts with, labelled in its default language.
     * Labels are data from then on, so they are not translation keys.
     *
     * @return list<self>
     */
    public static function defaults(string $locale): array
    {
        $groups = $locale === 'en'
            ? ['D' => 'Director', 'S1' => 'Soprano 1', 'S2' => 'Soprano 2', 'A1' => 'Alto 1', 'A2' => 'Alto 2', 'T1' => 'Tenor 1', 'T2' => 'Tenor 2', 'B1' => 'Bass 1', 'B2' => 'Bass 2']
            : ['L' => 'Chorleitung', 'S1' => 'Sopran 1', 'S2' => 'Sopran 2', 'A1' => 'Alt 1', 'A2' => 'Alt 2', 'T1' => 'Tenor 1', 'T2' => 'Tenor 2', 'B1' => 'Bass 1', 'B2' => 'Bass 2'];

        return array_map(static fn(string $key, string $label): self => new self($key, $label), array_keys($groups), $groups);
    }
}
