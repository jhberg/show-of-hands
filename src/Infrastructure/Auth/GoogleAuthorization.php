<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Auth;

/**
 * Where to send the visitor, and the two secrets the callback must present again.
 */
final readonly class GoogleAuthorization
{
    public function __construct(
        public string $url,
        public string $state,
        public string $verifier,
    ) {}
}
