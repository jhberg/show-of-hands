<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\CastOutcome;
use ShowOfHands\Domain\Choice;

final class CastOutcomeTest extends TestCase
{
    public function testAFirstTapRegistersTheVote(): void
    {
        self::assertSame(CastOutcome::Registered, CastOutcome::of(null, Choice::Yes));
        self::assertSame(CastOutcome::Registered, CastOutcome::of(null, Choice::No));
    }

    public function testTheOtherChoiceChangesTheVote(): void
    {
        self::assertSame(CastOutcome::Changed, CastOutcome::of(Choice::Yes, Choice::No));
        self::assertSame(CastOutcome::Changed, CastOutcome::of(Choice::No, Choice::Yes));
    }

    public function testTheSameChoiceKeepsTheVote(): void
    {
        self::assertSame(CastOutcome::Unchanged, CastOutcome::of(Choice::Yes, Choice::Yes));
        self::assertSame(CastOutcome::Unchanged, CastOutcome::of(Choice::No, Choice::No));
    }
}
