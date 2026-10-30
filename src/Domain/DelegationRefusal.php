<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Why a member cannot hand their vote to the chosen member.
 */
enum DelegationRefusal
{
    /** Admins have switched delegation off. Existing delegations stay. */
    case Disabled;

    /** The member chose themselves. */
    case ToSelf;

    /** The chosen member is not on the member list. */
    case UnknownTarget;

    /** The chosen member has handed their own vote on, and a delegated vote cannot be passed on. */
    case TargetDelegated;

    /** The chosen member already holds as many extra votes as the limit allows. */
    case TargetFull;

    /** The member holds votes of others, so their own vote cannot be passed on. */
    case SenderReceived;

    /** The member has handed their vote on already. They take it back first. */
    case SenderDelegated;
}
