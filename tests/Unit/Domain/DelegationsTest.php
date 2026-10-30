<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\DelegationRefusal;
use ShowOfHands\Domain\Delegations;
use ShowOfHands\Domain\Member;

final class DelegationsTest extends TestCase
{
    private const LIMIT = 2;

    /**
     * @return list<Member>
     */
    private static function members(): array
    {
        return array_map(static fn(int $id): Member => new Member($id, "Member {$id}", 'S1', false, false), [1, 2, 3, 4, 5, 6]);
    }

    /**
     * @param list<Member> $members
     *
     * @return list<int>
     */
    private static function ids(array $members): array
    {
        return array_map(static fn(Member $member): int => $member->id, $members);
    }

    public function testAFreeMemberMayDelegateToAnotherFreeMember(): void
    {
        self::assertNull((new Delegations())->refusal(1, 2, true, self::LIMIT));
    }

    public function testNothingNewWhileDelegationIsSwitchedOff(): void
    {
        $delegations = new Delegations([3 => 4]);

        self::assertSame(DelegationRefusal::Disabled, $delegations->refusal(1, 2, false, self::LIMIT));
        // Switching it off keeps the delegation that exists.
        self::assertSame(4, $delegations->recipientOf(3));
    }

    public function testNobodyDelegatesToThemselves(): void
    {
        self::assertSame(DelegationRefusal::ToSelf, (new Delegations())->refusal(1, 1, true, self::LIMIT));
    }

    public function testADelegatedVoteIsNotPassedOn(): void
    {
        // 2 holds the vote of 1, so 2 cannot hand votes on, and nobody can delegate to 1.
        $delegations = new Delegations([1 => 2]);

        self::assertSame(DelegationRefusal::SenderReceived, $delegations->refusal(2, 3, true, self::LIMIT));
        self::assertSame(DelegationRefusal::TargetDelegated, $delegations->refusal(3, 1, true, self::LIMIT));
    }

    public function testAMemberWhoDelegatedTakesItBackFirst(): void
    {
        self::assertSame(DelegationRefusal::SenderDelegated, (new Delegations([1 => 2]))->refusal(1, 3, true, self::LIMIT));
    }

    public function testTheLimitCapsTheExtraVotesOfOneMember(): void
    {
        $delegations = new Delegations([1 => 5, 2 => 5]);

        self::assertSame(DelegationRefusal::TargetFull, $delegations->refusal(3, 5, true, 2));
        self::assertNull($delegations->refusal(3, 5, true, 3));
        self::assertSame(DelegationRefusal::TargetFull, (new Delegations([1 => 5]))->refusal(3, 5, true, 1));
    }

    public function testKnowsWhoHandedTheirVoteToWhom(): void
    {
        $delegations = new Delegations([1 => 5, 2 => 5, 3 => 4]);

        self::assertSame([1, 2], $delegations->sendersTo(5));
        self::assertSame([], $delegations->sendersTo(6));
        self::assertTrue($delegations->hasDelegated(3));
        self::assertNull($delegations->recipientOf(5));
    }

    public function testOffersEveryoneElseWhoIsFreeAndBelowTheLimit(): void
    {
        // 1 delegated to 5, 2 to 5 (full at 2), 3 to 4 (one more fits).
        $delegations = new Delegations([1 => 5, 2 => 5, 3 => 4]);

        self::assertSame([4], self::ids($delegations->targets(self::members(), 6, 2)));
        self::assertSame([4, 5, 6], self::ids($delegations->targets(self::members(), 2, 3)));
        self::assertSame([2, 3, 4, 5, 6], self::ids((new Delegations())->targets(self::members(), 1, 1)));
    }

    public function testEveryOfferedTargetIsAccepted(): void
    {
        $delegations = new Delegations([1 => 5, 3 => 4]);

        foreach ($delegations->targets(self::members(), 6, 2) as $target) {
            self::assertNull($delegations->refusal(6, $target->id, true, 2), "Member {$target->id} was offered but refused.");
        }
    }
}
