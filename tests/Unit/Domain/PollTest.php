<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;

final class PollTest extends TestCase
{
    private static function poll(PollKind $kind, PollStatus $status): Poll
    {
        return new Poll(1, $kind, 'Robin Example', $kind === PollKind::Exception ? 'May Robin sing?' : null, 'S1', null, '', $status);
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
}
