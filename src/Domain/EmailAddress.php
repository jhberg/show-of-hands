<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Email addresses identify members. They compare in lower case, because Google reports
 * the case the account was created with, and members type their address in any case.
 */
final class EmailAddress
{
    /** At most this many addresses per member, for example the choir account and a private one. */
    public const MAX_PER_MEMBER = 2;

    /**
     * @return string|null the lowercased address, or null when it is not a valid address
     */
    public static function normalize(string $address): ?string
    {
        $address = mb_strtolower(trim($address));

        return filter_var($address, FILTER_VALIDATE_EMAIL) === false ? null : $address;
    }
}
