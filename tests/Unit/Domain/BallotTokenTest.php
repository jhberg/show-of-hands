<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\BallotToken;

final class BallotTokenTest extends TestCase
{
    private const SECRET = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

    public function testTheTokenIsTheHmacOfPollAndMemberUnderTheSecret(): void
    {
        $expected = hash_hmac('sha256', pack('NN', 12, 7), (string) hex2bin(self::SECRET), true);

        self::assertSame($expected, (new BallotToken(self::SECRET))->for(12, 7));
        self::assertSame(32, strlen($expected));
    }

    public function testTheSameMemberGetsTheSameTokenInTheSamePoll(): void
    {
        self::assertSame((new BallotToken(self::SECRET))->for(3, 4), (new BallotToken(strtoupper(self::SECRET)))->for(3, 4));
    }

    public function testTokensDifferByPollMemberAndSecret(): void
    {
        $tokens = new BallotToken(self::SECRET);
        $other = new BallotToken(str_repeat('ab', 32));

        self::assertCount(5, array_unique([
            $tokens->for(1, 2),
            $tokens->for(2, 1),
            $tokens->for(1, 3),
            $tokens->for(3, 2),
            $other->for(1, 2),
        ]));
    }

    public function testRejectsAShortOrMalformedSecret(): void
    {
        foreach (['', str_repeat('ab', 31), str_repeat('ab', 32) . 'a', str_repeat('zz', 32)] as $secret) {
            try {
                new BallotToken($secret);
                self::fail("Accepted '{$secret}'.");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
