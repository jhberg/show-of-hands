<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Why the details an admin entered do not make a poll.
 */
enum PollDetailsError
{
    case NameMissing;

    /** A probation exception without the question it asks. */
    case QuestionMissing;
}
