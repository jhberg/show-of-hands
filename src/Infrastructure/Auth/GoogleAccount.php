<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Auth;

final readonly class GoogleAccount
{
    public function __construct(
        public string $email,
        public bool $verified,
    ) {}
}
