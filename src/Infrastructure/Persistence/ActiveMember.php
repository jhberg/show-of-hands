<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * The check at the start of a vote or a delegation. Inside a BEGIN IMMEDIATE transaction it
 * holds until the commit, so no vote lands after the member was set inactive.
 */
final class ActiveMember
{
    /**
     * @throws MemberInactiveException
     */
    public static function require(\PDO $pdo, int $memberId): void
    {
        $statement = $pdo->prepare('SELECT 1 FROM members WHERE id = ? AND is_active = 1');
        $statement->execute([$memberId]);
        if ($statement->fetchColumn() === false) {
            throw new MemberInactiveException($memberId);
        }
    }
}
