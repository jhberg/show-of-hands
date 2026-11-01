<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Why the details an admin entered do not make a member.
 */
enum MemberDetailsError
{
    case NameMissing;

    /** Both address fields are empty. */
    case AddressMissing;

    case FirstAddressInvalid;

    case SecondAddressInvalid;

    case SameAddresses;
}
