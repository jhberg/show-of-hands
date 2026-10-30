<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Who has voted in an open poll, who handed their vote on, and who is still missing. It knows
 * only that members voted, never what they chose, so it can be shown while the poll is open.
 */
final readonly class Turnout
{
    /**
     * @param int $eligible every member, each with one voting right
     * @param int $castWeight the voting rights cast so far: each ballot with the votes handed to its member
     * @param int $voted the members with a ballot
     * @param list<Member> $missing the members without a ballot who kept their vote, in voice group order, then by name
     * @param list<array{from: Member, to: Member, to_voted: bool}> $delegations in the delegating members' order
     */
    private function __construct(
        public int $eligible,
        public int $castWeight,
        public int $voted,
        public array $missing,
        public array $delegations,
    ) {}

    /**
     * A delegated vote counts as cast only once its recipient has voted.
     *
     * @param list<Member> $members every member, in voice group order, then by name
     * @param list<int> $participantIds the members who have voted
     */
    public static function of(array $members, array $participantIds, Delegations $delegations = new Delegations()): self
    {
        $voted = array_flip($participantIds);
        $byId = array_column($members, null, 'id');
        $missing = [];
        $delegated = [];
        $castWeight = 0;
        $votedCount = 0;
        foreach ($members as $member) {
            $recipient = $byId[$delegations->recipientOf($member->id) ?? 0] ?? null;
            if (isset($voted[$member->id])) {
                ++$votedCount;
                ++$castWeight;
            } elseif ($recipient !== null) {
                $toVoted = isset($voted[$recipient->id]);
                $delegated[] = ['from' => $member, 'to' => $recipient, 'to_voted' => $toVoted];
                if ($toVoted) {
                    ++$castWeight;
                }
            } else {
                $missing[] = $member;
            }
        }

        return new self(count($members), $castWeight, $votedCount, $missing, $delegated);
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
