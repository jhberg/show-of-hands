<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Database;

/**
 * The database holds migrations this release does not list: an older release was unpacked over a
 * newer one, and the backup was not moved over the database. The release must not run on it.
 */
final class DatabaseAheadException extends \RuntimeException
{
    /**
     * @param ?string $backup the backup to move over the database, from data/ on, or null if there is none
     */
    public function __construct(public readonly ?string $backup)
    {
        parent::__construct('The database is newer than this release.');
    }
}
