<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Why a poll cannot be reopened.
 */
enum ReopenRefusal
{
    /** It is a draft or open, for example because another admin reopened it first. */
    case NotClosed;

    /** Its close day has ended, so the result is final. */
    case TooLate;
}
