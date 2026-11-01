<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Domain\VoiceGroupError;

final class VoiceGroupTest extends TestCase
{
    public function testTrimsAndUpperCasesTheKeyAndTrimsTheLabel(): void
    {
        $group = VoiceGroup::parse(' s3 ', ' Sopran 3 ');

        self::assertEquals(new VoiceGroup('S3', 'Sopran 3'), $group);
    }

    /**
     * @return iterable<string, array{string, string, VoiceGroupError}>
     */
    public static function refused(): iterable
    {
        yield 'no key' => [' ', 'Sopran 3', VoiceGroupError::KeyMissing];
        yield 'no label' => ['S3', ' ', VoiceGroupError::LabelMissing];
        yield 'neither' => ['', '', VoiceGroupError::KeyMissing];
        yield 'five characters' => ['SOPR3', 'Sopran 3', VoiceGroupError::KeyInvalid];
        yield 'umlaut' => ['ä1', 'Alt 1', VoiceGroupError::KeyInvalid];
        yield 'space inside' => ['S 3', 'Sopran 3', VoiceGroupError::KeyInvalid];
    }

    #[DataProvider('refused')]
    public function testRefuses(string $key, string $label, VoiceGroupError $error): void
    {
        self::assertSame($error, VoiceGroup::parse($key, $label));
    }
}
