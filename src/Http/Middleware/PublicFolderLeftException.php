<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

/**
 * The update from a release up to 0.4.0 is not finished: the installation still holds public/.
 */
final class PublicFolderLeftException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The installation still holds the folder public/ of an older release.');
    }
}
