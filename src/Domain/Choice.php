<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

enum Choice: string
{
    case Yes = 'yes';
    case No = 'no';
}
