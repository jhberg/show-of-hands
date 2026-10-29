<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Infrastructure\Auth\GoogleAccount;
use ShowOfHands\Infrastructure\Auth\GoogleAuthorization;
use ShowOfHands\Infrastructure\Auth\GoogleSignIn;
use ShowOfHands\Infrastructure\Auth\GoogleSignInException;

/**
 * Stands in for Google: start() hands out a fixed state, finish() returns the account set by the test.
 */
final class FakeGoogleSignIn implements GoogleSignIn
{
    public const STATE = 'state-123';
    public const VERIFIER = 'verifier-456';

    public ?GoogleAccount $account = null;

    /** @var list<array{string, string}> code and verifier of every finish() call */
    public array $finished = [];

    public function start(): GoogleAuthorization
    {
        return new GoogleAuthorization('https://accounts.google.test/auth?state=' . self::STATE, self::STATE, self::VERIFIER);
    }

    public function finish(string $code, string $verifier): GoogleAccount
    {
        $this->finished[] = [$code, $verifier];
        if ($this->account === null) {
            throw new GoogleSignInException('Google refused the code.');
        }

        return $this->account;
    }
}
