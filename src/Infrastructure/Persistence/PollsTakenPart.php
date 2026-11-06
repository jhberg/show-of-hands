<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\CloseDay;
use ShowOfHands\Domain\PollStatus;

/**
 * The polls in which a member voted, delegated, or received a vote, and which still count with
 * them: an open poll, or one closed today, which an admin may still reopen. Turnout and
 * delegations tell this; the ballots are not read. Without a member, every such poll counts.
 */
final class PollsTakenPart
{
    /**
     * Whether such a poll holds the member, or any member when $memberId is null: true while one
     * is open, false while only one closed today does, null when none does. An open poll wins,
     * since it holds them longer.
     */
    public static function hold(\PDO $pdo, ?int $memberId, \DateTimeImmutable $now, \DateTimeZone $zone): ?bool
    {
        $takenPart = $memberId === null ? '' : 'AND (
                EXISTS (SELECT 1 FROM participation WHERE poll_id = p.id AND member_id = ?)
                OR EXISTS (SELECT 1 FROM delegations WHERE poll_id = p.id AND (from_member_id = ? OR to_member_id = ?))
            )';
        $statement = $pdo->prepare(
            'SELECT CASE WHEN status = ? THEN closed_at END AS closed_at FROM polls p
             WHERE status IN (?, ?) ' . $takenPart . '
             ORDER BY closed_at IS NOT NULL',
        );
        $statement->execute([PollStatus::Closed->value, PollStatus::Closed->value, PollStatus::Open->value, ...($memberId === null ? [] : [$memberId, $memberId, $memberId])]);
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $closedAt = Row::nullableString($row, 'closed_at');
            if ($closedAt === null) {
                return true;
            }
            if (!CloseDay::hasEnded(new \DateTimeImmutable($closedAt), $now, $zone)) {
                return false;
            }
        }

        return null;
    }
}
