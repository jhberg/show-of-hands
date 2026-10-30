<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Derives the key of a member's ballot in one poll: HMAC-SHA256 of (poll id, member id) under
 * the app secret. The ballot table stores only this token, so it names nobody. The server can
 * recompute the token from a request to find the member's own ballot and change it.
 */
final readonly class BallotToken
{
    /** At least 32 bytes as hex. The config loader checks app.secret against it. */
    public const SECRET_PATTERN = '/^(?:[0-9a-f]{2}){32,}$/i';

    private string $key;

    /**
     * @param string $secret the app secret as hex, at least 32 bytes
     */
    public function __construct(string $secret)
    {
        if (preg_match(self::SECRET_PATTERN, $secret) !== 1) {
            throw new \InvalidArgumentException('The ballot secret must be at least 32 bytes as hex.');
        }
        $this->key = (string) hex2bin($secret);
    }

    /**
     * @return non-empty-string 32 raw bytes
     */
    public function for(int $pollId, int $memberId): string
    {
        return hash_hmac('sha256', pack('NN', $pollId, $memberId), $this->key, true);
    }
}
