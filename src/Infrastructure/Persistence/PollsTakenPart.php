<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\PollStatus;

/**
 * The polls in which a member voted, delegated, or received a vote. Turnout and delegations tell
 * this; the ballots are not read.
 */
final class PollsTakenPart
{
    /**
     * When each such poll closed, or null for one that is open.
     *
     * @param bool $neverClosed whether open polls that were never closed count too, not only reopened ones
     *
     * @return list<string|null>
     */
    public static function closings(\PDO $pdo, int $memberId, bool $neverClosed): array
    {
        $statement = $pdo->prepare(
            'SELECT CASE WHEN status = ? THEN closed_at END AS closed_at FROM polls p
             WHERE (status = ? OR (status = ? AND (? OR reopen_count > 0))) AND (
                EXISTS (SELECT 1 FROM participation WHERE poll_id = p.id AND member_id = ?)
                OR EXISTS (SELECT 1 FROM delegations WHERE poll_id = p.id AND (from_member_id = ? OR to_member_id = ?))
            )',
        );
        $statement->execute([PollStatus::Closed->value, PollStatus::Closed->value, PollStatus::Open->value, (int) $neverClosed, $memberId, $memberId, $memberId]);
        $polls = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $polls[] = Row::nullableString($row, 'closed_at');
        }

        return $polls;
    }
}
