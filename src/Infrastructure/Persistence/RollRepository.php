<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\Delegations;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\Turnout;

/**
 * The turnout snapshot of a closed poll: who voted, who handed their vote to whom, and who was
 * missing at the close. It never holds a choice.
 */
final readonly class RollRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * Stores the turnout at the close. The caller runs it inside the close's transaction.
     *
     * @param list<Member> $members every member at the close
     */
    public function write(int $pollId, array $members, Turnout $turnout): void
    {
        $missing = array_flip(array_map(static fn(Member $member): int => $member->id, $turnout->missing));
        $recipients = [];
        foreach ($turnout->delegations as $entry) {
            $recipients[$entry['from']->id] = $entry['to']->id;
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO poll_roll (poll_id, member_id, name, voice_group_key, status, delegated_to_member_id) VALUES (?, ?, ?, ?, ?, ?)',
        );
        foreach ($members as $member) {
            $status = match (true) {
                isset($missing[$member->id]) => 'missing',
                isset($recipients[$member->id]) => 'delegated',
                default => 'voted',
            };
            $insert->execute([$pollId, $member->id, $member->name, $member->voiceGroupKey, $status, $recipients[$member->id] ?? null]);
        }
    }

    /**
     * The turnout of a closed poll as it stood at the close, its members in today's voice group
     * order, then by name.
     */
    public function turnout(int $pollId, string $locale): Turnout
    {
        $statement = $this->pdo->prepare(
            'SELECT r.member_id, r.name, r.voice_group_key, r.status, r.delegated_to_member_id, coalesce(g.position, 999999) AS group_position
             FROM poll_roll r
             LEFT JOIN voice_groups g ON g.key = r.voice_group_key
             WHERE r.poll_id = ?',
        );
        $statement->execute([$pollId]);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $rows[] = $row;
        }
        $collator = new \Collator($locale);
        usort($rows, static fn(array $a, array $b): int => Row::int($a, 'group_position') <=> Row::int($b, 'group_position')
            ?: (int) $collator->compare(Row::string($a, 'name'), Row::string($b, 'name')));

        $members = [];
        $voted = [];
        $recipients = [];
        foreach ($rows as $row) {
            $id = Row::int($row, 'member_id');
            $members[] = new Member($id, Row::string($row, 'name'), Row::nullableString($row, 'voice_group_key'), isBoard: false, isAdmin: false);
            $status = Row::string($row, 'status');
            if ($status === 'voted') {
                $voted[] = $id;
            } elseif ($status === 'delegated') {
                $recipients[$id] = Row::int($row, 'delegated_to_member_id');
            }
        }

        return Turnout::of($members, $voted, new Delegations($recipients));
    }
}
