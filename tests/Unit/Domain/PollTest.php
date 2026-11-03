<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\Majority;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\PollEditing;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\ReopenRefusal;

final class PollTest extends TestCase
{
    private static function poll(PollKind $kind, PollStatus $status, ?string $closedAt = null, ?string $reopenedAt = null): Poll
    {
        return new Poll(1, $kind, 'Robin Example', $kind === PollKind::Exception ? 'May Robin sing?' : null, 'S1', null, '', $status, Majority::default(), closedAt: $closedAt, reopenedAt: $reopenedAt);
    }

    public function testMembersSeeOnlyPublishedPolls(): void
    {
        self::assertFalse(self::poll(PollKind::Admission, PollStatus::Draft)->isPublished());
        self::assertTrue(self::poll(PollKind::Admission, PollStatus::Open)->isPublished());
        self::assertTrue(self::poll(PollKind::Admission, PollStatus::Closed)->isPublished());
    }

    public function testTheHeadShowsTheExceptionQuestionOnlyOnceThePollIsClosed(): void
    {
        self::assertFalse(self::poll(PollKind::Exception, PollStatus::Open)->showsQuestionInHead());
        self::assertTrue(self::poll(PollKind::Exception, PollStatus::Closed)->showsQuestionInHead());
        self::assertFalse(self::poll(PollKind::Admission, PollStatus::Closed)->showsQuestionInHead());
    }

    public function testADraftIsEditableInFullAnOpenPollOnlyInNoteAndDateAClosedOneNotAtAll(): void
    {
        self::assertSame(PollEditing::All, self::poll(PollKind::Admission, PollStatus::Draft)->editing());
        self::assertSame(PollEditing::NoteAndDate, self::poll(PollKind::Admission, PollStatus::Open)->editing());
        self::assertSame(PollEditing::Nothing, self::poll(PollKind::Admission, PollStatus::Closed, '2026-11-03T19:00:00Z')->editing());
    }

    public function testAPollReopensOnlyOnTheDayItClosedInTheChoirsTimeZone(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        // 23:30 in Berlin.
        $closed = self::poll(PollKind::Admission, PollStatus::Closed, '2026-11-03T22:30:00Z');

        self::assertNull($closed->reopenRefusal(new \DateTimeImmutable('2026-11-03T22:59:59Z'), $berlin));
        self::assertSame(ReopenRefusal::TooLate, $closed->reopenRefusal(new \DateTimeImmutable('2026-11-03T23:00:00Z'), $berlin));
        self::assertSame(ReopenRefusal::NotClosed, self::poll(PollKind::Admission, PollStatus::Open)->reopenRefusal(new \DateTimeImmutable('2026-11-03T22:40:00Z'), $berlin));
        self::assertSame(ReopenRefusal::NotClosed, self::poll(PollKind::Admission, PollStatus::Draft)->reopenRefusal(new \DateTimeImmutable('2026-11-03T22:40:00Z'), $berlin));
    }

    public function testOnlyAnOpenPollThatWasReopenedShowsTheNotice(): void
    {
        self::assertTrue(self::poll(PollKind::Admission, PollStatus::Open, reopenedAt: '2026-11-03T20:00:00Z')->isReopened());
        self::assertFalse(self::poll(PollKind::Admission, PollStatus::Open)->isReopened());
        self::assertFalse(self::poll(PollKind::Admission, PollStatus::Closed, '2026-11-03T21:00:00Z', '2026-11-03T20:00:00Z')->isReopened());
    }

    public function testTheDeletionDayIsTheRetentionAfterTheCloseInTheChoirsTimeZone(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        self::assertNull(self::poll(PollKind::Admission, PollStatus::Open)->deletionDay(14, $berlin));
        self::assertSame('2026-11-18', self::poll(PollKind::Admission, PollStatus::Closed, '2026-11-03T23:30:00Z')->deletionDay(14, $berlin));
    }
}
