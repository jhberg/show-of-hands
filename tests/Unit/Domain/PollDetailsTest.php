<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\PollDetails;
use ShowOfHands\Domain\PollDetailsError;
use ShowOfHands\Domain\PollKind;

final class PollDetailsTest extends TestCase
{
    public function testEveryPollNeedsTheName(): void
    {
        self::assertSame(PollDetailsError::NameMissing, PollDetails::parse(PollKind::Admission, '  ', '', 'S1', '', ''));
    }

    public function testAnExceptionNeedsTheQuestionAndAnAdmissionKeepsNone(): void
    {
        self::assertSame(PollDetailsError::QuestionMissing, PollDetails::parse(PollKind::Exception, 'Robin Example', ' ', 'S1', '', ''));

        $admission = PollDetails::parse(PollKind::Admission, 'Robin Example', 'Left over from the other kind', 'S1', '', '');
        self::assertInstanceOf(PollDetails::class, $admission);
        self::assertNull($admission->question);
    }

    public function testValuesAreTrimmedAndAnEmptyDateStaysOpen(): void
    {
        $details = PollDetails::parse(PollKind::Exception, ' Robin Example ', ' May Robin sing? ', 'B1', ' ', ' Note ');

        self::assertInstanceOf(PollDetails::class, $details);
        self::assertSame(['Robin Example', 'May Robin sing?', 'B1', null, 'Note'], [$details->subjectName, $details->question, $details->voiceGroupKey, $details->date, $details->note]);
    }

    public function testADateMustBeARealDay(): void
    {
        $leapDay = PollDetails::parse(PollKind::Admission, 'Robin Example', '', 'S1', '2028-02-29', '');
        self::assertInstanceOf(PollDetails::class, $leapDay);
        self::assertSame('2028-02-29', $leapDay->date);
        foreach (['2027-02-29', '29.02.2028', '2028-2-9'] as $invalid) {
            try {
                PollDetails::parse(PollKind::Admission, 'Robin Example', '', 'S1', $invalid, '');
                self::fail("{$invalid} passed.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
