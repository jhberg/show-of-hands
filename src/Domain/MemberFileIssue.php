<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Why a row of the member file makes no member, beyond what MemberDetails refuses.
 */
enum MemberFileIssue
{
    /** The first line lacks the column name or email. */
    case HeaderInvalid;

    /** An address that a row above already uses. */
    case AddressRepeated;

    /** A group that is neither a key nor a label of an existing voice group. */
    case GroupUnknown;

    /** A board or admin cell that is neither yes nor no. */
    case RoleInvalid;
}
