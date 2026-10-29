<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * The first member, created with board and admin roles while the member list is empty.
 */
final readonly class BootstrapAdmin
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $group,
    ) {}
}
