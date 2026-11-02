<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\Majority;
use ShowOfHands\Domain\MajorityBase;
use ShowOfHands\Domain\MajorityShare;
use ShowOfHands\Domain\Tally;

final class TallyTest extends TestCase
{
    /**
     * @return list<array{Choice, int}> ballots of weight 1
     */
    private static function ballots(Choice $choice, int $count): array
    {
        return array_fill(0, $count, [$choice, 1]);
    }

    public function testCountsEachBallotAsOneVotingRightWithoutDelegations(): void
    {
        $tally = Tally::count(25, [...self::ballots(Choice::Yes, 16), ...self::ballots(Choice::No, 7)], Majority::default());

        self::assertSame(16, $tally->yes);
        self::assertSame(7, $tally->no);
        self::assertSame(23, $tally->cast());
        self::assertSame(2, $tally->abstained());
        self::assertSame(0, $tally->delegations);
        self::assertSame(17, $tally->needed());
        self::assertFalse($tally->isAccepted());
    }

    public function testADelegatedVoteCountsForTheChoiceOfItsRecipient(): void
    {
        // The mockup's closed poll: 16 Yes, one of them holding a delegated vote, and 7 No.
        $tally = Tally::count(25, [[Choice::Yes, 2], ...self::ballots(Choice::Yes, 15), ...self::ballots(Choice::No, 7)], Majority::default());

        self::assertSame(17, $tally->yes);
        self::assertSame(7, $tally->no);
        self::assertSame(24, $tally->cast());
        self::assertSame(1, $tally->abstained());
        self::assertSame(1, $tally->delegations);
        self::assertTrue($tally->isAccepted());
    }

    public function testDelegatedVotesAddUpOnBothSides(): void
    {
        $tally = Tally::count(10, [[Choice::Yes, 3], [Choice::No, 2], [Choice::No, 1]], Majority::default());

        self::assertSame(3, $tally->yes);
        self::assertSame(3, $tally->no);
        self::assertSame(3, $tally->delegations);
        self::assertSame(4, $tally->abstained());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function neededVotes(): iterable
    {
        yield 'divisible by 3' => [24, 16];
        yield 'one above a multiple of 3' => [25, 17];
        yield 'two above a multiple of 3' => [26, 18];
        yield 'one member' => [1, 1];
        yield 'two members' => [2, 2];
        yield 'three members' => [3, 2];
        yield 'four members' => [4, 3];
    }

    #[DataProvider('neededVotes')]
    public function testNeedsTwoThirdsOfTheEligibleVotersRoundedUp(int $eligible, int $needed): void
    {
        self::assertSame($needed, (new Tally($eligible, 0, 0, 0, Majority::default()))->needed());
    }

    #[DataProvider('neededVotes')]
    public function testIsAcceptedExactlyAtTwoThirds(int $eligible, int $needed): void
    {
        self::assertTrue((new Tally($eligible, $needed, 0, 0, Majority::default()))->isAccepted());
        self::assertFalse((new Tally($eligible, $needed - 1, $eligible - $needed + 1, 0, Majority::default()))->isAccepted());
    }

    public function testTwoThirdsOfTheVotesCastAreNotEnough(): void
    {
        // 10 of 15 votes cast are Yes, but 30 members are eligible.
        $tally = Tally::count(30, [...self::ballots(Choice::Yes, 10), ...self::ballots(Choice::No, 5)], Majority::default());

        self::assertSame(20, $tally->needed());
        self::assertFalse($tally->isAccepted());
    }

    public function testSharesAreOfTheEligibleVoters(): void
    {
        $tally = new Tally(25, 17, 7, 1, Majority::default());

        self::assertEqualsWithDelta(0.68, $tally->share($tally->yes), 1e-9);
        self::assertEqualsWithDelta(0.28, $tally->share($tally->no), 1e-9);
        self::assertEqualsWithDelta(0.04, $tally->share($tally->abstained()), 1e-9);
        self::assertEqualsWithDelta(0.68, $tally->share($tally->needed()), 1e-9);
    }

    public function testAPollWithoutEligibleVotersIsNotAccepted(): void
    {
        $tally = Tally::count(0, [], Majority::default());

        self::assertFalse($tally->isAccepted());
        self::assertSame(0.0, $tally->share(0));
    }

    /**
     * @return iterable<string, array{MajorityShare, int, int}>
     */
    public static function neededByShare(): iterable
    {
        yield 'more than half of an even number' => [MajorityShare::Half, 24, 13];
        yield 'more than half of an odd number' => [MajorityShare::Half, 25, 13];
        yield 'three quarters, divisible' => [MajorityShare::ThreeQuarters, 24, 18];
        yield 'three quarters, rounded up' => [MajorityShare::ThreeQuarters, 25, 19];
        yield 'at least one of nothing' => [MajorityShare::TwoThirds, 0, 1];
    }

    #[DataProvider('neededByShare')]
    public function testEachShareRoundsToTheVotesItNeeds(MajorityShare $share, int $total, int $needed): void
    {
        self::assertSame($needed, $share->needed($total));
    }

    public function testCountedFromTheVotesCastAbstentionsDoNotCount(): void
    {
        // 10 of 15 votes cast are Yes; 30 members are eligible.
        $tally = Tally::count(30, [...self::ballots(Choice::Yes, 10), ...self::ballots(Choice::No, 5)], new Majority(MajorityShare::TwoThirds, MajorityBase::Cast));

        self::assertSame(15, $tally->base());
        self::assertSame(10, $tally->needed());
        self::assertTrue($tally->isAccepted());
        self::assertEqualsWithDelta(2 / 3, $tally->yesShareOfBase(), 1e-9);
        self::assertEqualsWithDelta(1 / 3, $tally->share($tally->yes), 1e-9);
    }

    public function testMoreThanHalfOfTheVotesCastNeedsMoreYesThanNo(): void
    {
        $half = new Majority(MajorityShare::Half, MajorityBase::Cast);

        self::assertFalse(Tally::count(30, [...self::ballots(Choice::Yes, 5), ...self::ballots(Choice::No, 5)], $half)->isAccepted());
        self::assertTrue(Tally::count(30, [...self::ballots(Choice::Yes, 6), ...self::ballots(Choice::No, 5)], $half)->isAccepted());
    }

    public function testNobodyVotingPassesNothingCountedFromTheVotesCast(): void
    {
        self::assertFalse(Tally::count(30, [], new Majority(MajorityShare::Half, MajorityBase::Cast))->isAccepted());
    }
}
