<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Who has voted in an open poll, and who is still missing. It knows only that members voted,
 * never what they chose, so it can be shown while the poll is open.
 */
final readonly class Turnout
{
    /**
     * @param int $eligible every member, each with one voting right
     * @param int $castWeight the voting rights cast so far
     * @param int $voted the members with a ballot
     * @param list<Member> $missing the members without a ballot, in voice group order, then by name
     */
    private function __construct(
        public int $eligible,
        public int $castWeight,
        public int $voted,
        public array $missing,
    ) {}

    /**
     * @param list<Member> $members every member, in voice group order, then by name
     * @param list<int> $participantIds the members who have voted
     */
    public static function of(array $members, array $participantIds): self
    {
        $voted = array_flip($participantIds);
        $missing = array_values(array_filter($members, static fn(Member $member): bool => !isset($voted[$member->id])));
        $castWeight = count($members) - count($missing);

        return new self(count($members), $castWeight, $castWeight, $missing);
    }

    /** The share of cast voting rights, rounded to whole percent, for the progress bar. */
    public function percent(): int
    {
        return $this->eligible === 0 ? 0 : (int) round($this->castWeight / $this->eligible * 100);
    }

    /**
     * The missing members by voice group, in the groups' order. Members without a group, or
     * with a group that no longer exists, come last under a null group.
     *
     * @param list<VoiceGroup> $groups in the order admins set
     *
     * @return list<array{group: VoiceGroup|null, members: non-empty-list<Member>}>
     */
    public function missingByGroup(array $groups): array
    {
        $byKey = [];
        $other = [];
        $known = array_flip(array_map(static fn(VoiceGroup $group): string => $group->key, $groups));
        foreach ($this->missing as $member) {
            if ($member->voiceGroupKey !== null && isset($known[$member->voiceGroupKey])) {
                $byKey[$member->voiceGroupKey][] = $member;
            } else {
                $other[] = $member;
            }
        }
        $result = [];
        foreach ($groups as $group) {
            if (isset($byKey[$group->key])) {
                $result[] = ['group' => $group, 'members' => $byKey[$group->key]];
            }
        }
        if ($other !== []) {
            $result[] = ['group' => null, 'members' => $other];
        }

        return $result;
    }
}
