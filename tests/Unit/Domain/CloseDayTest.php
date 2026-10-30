<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\CloseDay;

final class CloseDayTest extends TestCase
{
    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    public function testTheCloseDayLastsUntilMidnightInTheChoirsTimeZone(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');
        // 21:05 in Berlin, 20:05 UTC.
        $closedAt = self::at('2026-10-30T20:05:00Z');

        self::assertFalse(CloseDay::hasEnded($closedAt, self::at('2026-10-30T20:05:00Z'), $berlin));
        self::assertFalse(CloseDay::hasEnded($closedAt, self::at('2026-10-30T22:59:59Z'), $berlin));
        self::assertTrue(CloseDay::hasEnded($closedAt, self::at('2026-10-30T23:00:00Z'), $berlin));
    }

    public function testTheTimeZoneDecidesWhichDayThePollClosedOn(): void
    {
        // 00:30 in Berlin on the 31st, still the 30th in UTC.
        $closedAt = self::at('2026-10-30T23:30:00Z');
        $now = self::at('2026-10-31T10:00:00Z');

        self::assertFalse(CloseDay::hasEnded($closedAt, $now, new \DateTimeZone('Europe/Berlin')));
        self::assertTrue(CloseDay::hasEnded($closedAt, $now, new \DateTimeZone('UTC')));
    }
}
