<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The vote delegations of one poll: who handed their vote to whom.
 */
final readonly class Delegations
{
    /**
     * @param array<int, int> $recipients the recipient's member id by the delegating member's id
     */
    public function __construct(private array $recipients = []) {}

    /** The member the given member handed their vote to, or null. */
    public function recipientOf(int $memberId): ?int
    {
        return $this->recipients[$memberId] ?? null;
    }

    public function hasDelegated(int $memberId): bool
    {
        return isset($this->recipients[$memberId]);
    }

    /**
     * The members who handed their vote to the given member.
     *
     * @return list<int>
     */
    public function sendersTo(int $memberId): array
    {
        return array_keys($this->recipients, $memberId, true);
    }

    /**
     * @return array<int, int> the recipient's member id by the delegating member's id
     */
    public function all(): array
    {
        return $this->recipients;
    }

    /**
     * Why $from cannot hand their vote to $to, or null when they can. The caller checks that
     * the poll is open and that $to is a member.
     */
    public function refusal(int $from, int $to, bool $enabled, int $maxReceived): ?DelegationRefusal
    {
        return match (true) {
            !$enabled => DelegationRefusal::Disabled,
            $this->hasDelegated($from) => DelegationRefusal::SenderDelegated,
            $this->sendersTo($from) !== [] => DelegationRefusal::SenderReceived,
            default => $this->targetRefusal($from, $to, $maxReceived),
        };
    }

    /**
     * The members $memberId may hand their vote to: everyone else who has not handed their own
     * vote on and holds fewer than $maxReceived extra votes. The order of $members stays.
     *
     * @param list<Member> $members
     *
     * @return list<Member>
     */
    public function targets(array $members, int $memberId, int $maxReceived): array
    {
        return array_values(array_filter(
            $members,
            fn(Member $member): bool => $this->targetRefusal($memberId, $member->id, $maxReceived) === null,
        ));
    }

    private function targetRefusal(int $from, int $to, int $maxReceived): ?DelegationRefusal
    {
        return match (true) {
            $to === $from => DelegationRefusal::ToSelf,
            $this->hasDelegated($to) => DelegationRefusal::TargetDelegated,
            count($this->sendersTo($to)) >= $maxReceived => DelegationRefusal::TargetFull,
            default => null,
        };
    }
}
