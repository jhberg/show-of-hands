<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

final class DuplicateEmailException extends \RuntimeException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct("The address {$email} already belongs to a member.");
    }
}
