<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\Majority;
use ShowOfHands\Domain\Settings;

final class SettingsTest extends TestCase
{
    public function testAcceptsEveryOfferedChoice(): void
    {
        foreach (Settings::MAX_RECEIVED_CHOICES as $max) {
            foreach (Settings::RETENTION_DAYS_CHOICES as $days) {
                $settings = new Settings(true, $max, false, $days, Majority::default());
                self::assertSame([$max, $days], [$settings->maxReceived, $settings->retentionDays]);
            }
        }
    }

    #[TestWith([0, 14])]
    #[TestWith([4, 14])]
    #[TestWith([2, 0])]
    #[TestWith([2, 15])]
    public function testRefusesOtherValues(int $max, int $days): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Settings(true, $max, true, $days, Majority::default());
    }
}
