<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

enum PollStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
