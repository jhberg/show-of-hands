<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\Choice;
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
        $tally = Tally::count(25, [...self::ballots(Choice::Yes, 16), ...self::ballots(Choice::No, 7)]);

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
        $tally = Tally::count(25, [[Choice::Yes, 2], ...self::ballots(Choice::Yes, 15), ...self::ballots(Choice::No, 7)]);

        self::assertSame(17, $tally->yes);
        self::assertSame(7, $tally->no);
        self::assertSame(24, $tally->cast());
        self::assertSame(1, $tally->abstained());
        self::assertSame(1, $tally->delegations);
        self::assertTrue($tally->isAccepted());
    }

    public function testDelegatedVotesAddUpOnBothSides(): void
    {
        $tally = Tally::count(10, [[Choice::Yes, 3], [Choice::No, 2], [Choice::No, 1]]);

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
        self::assertSame($needed, (new Tally($eligible, 0, 0, 0))->needed());
    }

    #[DataProvider('neededVotes')]
    public function testIsAcceptedExactlyAtTwoThirds(int $eligible, int $needed): void
    {
        self::assertTrue((new Tally($eligible, $needed, 0, 0))->isAccepted());
        self::assertFalse((new Tally($eligible, $needed - 1, $eligible - $needed + 1, 0))->isAccepted());
    }

    public function testTwoThirdsOfTheVotesCastAreNotEnough(): void
    {
        // 10 of 15 votes cast are Yes, but 30 members are eligible.
        $tally = Tally::count(30, [...self::ballots(Choice::Yes, 10), ...self::ballots(Choice::No, 5)]);

        self::assertSame(20, $tally->needed());
        self::assertFalse($tally->isAccepted());
    }

    public function testSharesAreOfTheEligibleVoters(): void
    {
        $tally = new Tally(25, 17, 7, 1);

        self::assertEqualsWithDelta(0.68, $tally->share($tally->yes), 1e-9);
        self::assertEqualsWithDelta(0.28, $tally->share($tally->no), 1e-9);
        self::assertEqualsWithDelta(0.04, $tally->share($tally->abstained()), 1e-9);
        self::assertEqualsWithDelta(0.68, $tally->share($tally->needed()), 1e-9);
    }

    public function testAPollWithoutEligibleVotersIsNotAccepted(): void
    {
        $tally = Tally::count(0, []);

        self::assertFalse($tally->isAccepted());
        self::assertSame(0.0, $tally->share(0));
    }
}
