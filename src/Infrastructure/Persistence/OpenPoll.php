<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\PollStatus;

/**
 * The status check at the start of every write on an open poll. Inside a BEGIN IMMEDIATE
 * transaction it holds until the commit, so no write lands after the close that froze the tally.
 */
final class OpenPoll
{
    /**
     * @throws PollNotOpenException
     */
    public static function require(\PDO $pdo, int $pollId): void
    {
        $status = $pdo->prepare('SELECT status FROM polls WHERE id = ?');
        $status->execute([$pollId]);
        if ($status->fetchColumn() !== PollStatus::Open->value) {
            throw new PollNotOpenException($pollId);
        }
    }
}
