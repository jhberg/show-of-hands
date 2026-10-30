<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\Turnout;
use ShowOfHands\Domain\VoiceGroup;

final class TurnoutTest extends TestCase
{
    /**
     * @return list<Member> in voice group order, then by name, as the repository returns them
     */
    private static function members(): array
    {
        return [
            new Member(1, 'Ada Alt', 'S1', false, false),
            new Member(2, 'Bea Bach', 'S1', false, false),
            new Member(3, 'Cem Cord', 'T1', false, false),
            new Member(4, 'Dan Dorn', 'B1', false, false),
            new Member(5, 'Eva Eck', 'X9', false, false),
            new Member(6, 'Fin Fuchs', null, false, false),
        ];
    }

    /**
     * @return list<VoiceGroup>
     */
    private static function voiceGroups(): array
    {
        return [new VoiceGroup('S1', 'Soprano 1'), new VoiceGroup('T1', 'Tenor 1'), new VoiceGroup('B1', 'Bass 1')];
    }

    public function testCountsEveryMemberAsEligibleAndEachBallotAsOneVotingRight(): void
    {
        $turnout = Turnout::of(self::members(), [2, 4]);

        self::assertSame(6, $turnout->eligible);
        self::assertSame(2, $turnout->castWeight);
        self::assertSame(2, $turnout->voted);
        self::assertSame([1, 3, 5, 6], array_map(static fn(Member $m): int => $m->id, $turnout->missing));
        self::assertSame(33, $turnout->percent());
    }

    public function testIgnoresParticipationOfMembersWhoNoLongerExist(): void
    {
        $turnout = Turnout::of(self::members(), [1, 99]);

        self::assertSame(1, $turnout->castWeight);
        self::assertCount(5, $turnout->missing);
    }

    public function testEveryoneVoted(): void
    {
        $turnout = Turnout::of(self::members(), [1, 2, 3, 4, 5, 6]);

        self::assertSame([], $turnout->missing);
        self::assertSame(100, $turnout->percent());
        self::assertSame([], $turnout->missingByGroup(self::voiceGroups()));
    }

    public function testNoMembersMeansNoShare(): void
    {
        self::assertSame(0, Turnout::of([], [])->percent());
    }

    public function testGroupsTheMissingByVoiceGroupWithTheUngroupedLast(): void
    {
        $groups = Turnout::of(self::members(), [3])->missingByGroup(self::voiceGroups());

        self::assertSame(
            [['S1', [1, 2]], ['B1', [4]], [null, [5, 6]]],
            array_map(static fn(array $entry): array => [
                $entry['group']?->key,
                array_map(static fn(Member $m): int => $m->id, $entry['members']),
            ], $groups),
        );
    }
}
