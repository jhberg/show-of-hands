<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * What an admin may still change in a poll.
 */
enum PollEditing
{
    case All;

    case NoteAndDate;

    case Nothing;
}
