<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

enum PollKind: string
{
    /** A vote after an audition, with a fixed question. */
    case Admission = 'admission';

    /** A probation exception, with a question the admin writes. */
    case Exception = 'exception';
}
