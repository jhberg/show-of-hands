<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\MemberDetails;
use ShowOfHands\Domain\MemberDetailsError;

final class MemberDetailsTest extends TestCase
{
    public function testTrimsTheNameAndNormalizesBothAddresses(): void
    {
        $details = MemberDetails::parse('  Kim Example ', ' Kim@Example.ORG', 'kim@example.NET ');

        self::assertInstanceOf(MemberDetails::class, $details);
        self::assertSame('Kim Example', $details->name);
        self::assertSame(['kim@example.org', 'kim@example.net'], $details->emails);
    }

    public function testASecondAddressAloneBecomesTheFirst(): void
    {
        $details = MemberDetails::parse('Kim Example', ' ', 'kim@example.net');

        self::assertInstanceOf(MemberDetails::class, $details);
        self::assertSame(['kim@example.net'], $details->emails);
    }

    /**
     * @return iterable<string, array{string, string, string, MemberDetailsError}>
     */
    public static function refusals(): iterable
    {
        yield 'no name' => [' ', 'kim@example.org', '', MemberDetailsError::NameMissing];
        yield 'no address' => ['Kim Example', '', ' ', MemberDetailsError::AddressMissing];
        yield 'invalid first' => ['Kim Example', 'kim', '', MemberDetailsError::FirstAddressInvalid];
        yield 'invalid second' => ['Kim Example', 'kim@example.org', 'kim@', MemberDetailsError::SecondAddressInvalid];
        yield 'invalid second alone' => ['Kim Example', '', 'kim', MemberDetailsError::SecondAddressInvalid];
        yield 'same address in another case' => ['Kim Example', 'kim@example.org', 'KIM@example.org', MemberDetailsError::SameAddresses];
    }

    #[DataProvider('refusals')]
    public function testRefuses(string $name, string $email, string $secondEmail, MemberDetailsError $error): void
    {
        self::assertSame($error, MemberDetails::parse($name, $email, $secondEmail));
    }
}
