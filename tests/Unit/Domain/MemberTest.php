<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\EmailAddress;
use ShowOfHands\Domain\Member;

final class MemberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function names(): iterable
    {
        yield 'two words' => ['Kim Example', 'Kim', 'KE'];
        yield 'three words' => ['Anna Maria Example', 'Anna', 'AM'];
        yield 'one word' => ['Kim', 'Kim', 'K'];
        yield 'umlaut' => ['ölaf übel', 'ölaf', 'ÖÜ'];
        yield 'extra spaces' => ['  Kim   Example ', 'Kim', 'KE'];
    }

    #[DataProvider('names')]
    public function testDerivesFirstNameAndInitials(string $name, string $first, string $initials): void
    {
        $member = new Member(1, $name, 'S1', false, false);

        self::assertSame($first, $member->firstName());
        self::assertSame($initials, $member->initials());
    }

    public function testNormalizesEmailAddresses(): void
    {
        self::assertSame('kim@example.org', EmailAddress::normalize('  Kim@Example.ORG '));
        self::assertNull(EmailAddress::normalize('kim'));
        self::assertNull(EmailAddress::normalize(''));
    }
}
