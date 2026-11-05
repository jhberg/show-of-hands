<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * Light or dark mode as a member chose it. System follows the device's setting.
 */
enum ColorMode: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';
}
