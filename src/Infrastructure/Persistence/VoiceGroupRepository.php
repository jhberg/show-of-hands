<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Database\Transaction;

final readonly class VoiceGroupRepository
{
    public function __construct(private \PDO $pdo) {}

    /**
     * @return list<VoiceGroup> in the order admins set
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT key, label FROM voice_groups ORDER BY position');
        $groups = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            assert(is_array($row));
            $groups[] = new VoiceGroup(Row::string($row, 'key'), Row::string($row, 'label'));
        }

        return $groups;
    }

    public function find(string $key): ?VoiceGroup
    {
        $statement = $this->pdo->prepare('SELECT label FROM voice_groups WHERE key = ?');
        $statement->execute([$key]);
        $label = $statement->fetchColumn();

        return is_string($label) ? new VoiceGroup($key, $label) : null;
    }

    public function exists(string $key): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM voice_groups WHERE key = ?');
        $statement->execute([$key]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * The keys of the groups that members or polls still use. A closed poll's turnout snapshot
     * counts too, so its names keep their group label until the poll is deleted.
     *
     * @return list<string>
     */
    public function keysInUse(): array
    {
        $statement = $this->pdo->query(
            'SELECT voice_group_key FROM members WHERE voice_group_key IS NOT NULL
             UNION SELECT voice_group_key FROM polls
             UNION SELECT voice_group_key FROM poll_roll WHERE voice_group_key IS NOT NULL',
        );

        $keys = [];
        foreach ($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN) as $key) {
            assert(is_string($key));
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * Appends a group at the end of the order.
     *
     * @return bool false when the key is taken
     */
    public function add(VoiceGroup $group): bool
    {
        return Transaction::immediate($this->pdo, function () use ($group): bool {
            if ($this->exists($group->key)) {
                return false;
            }
            $this->pdo->prepare('INSERT INTO voice_groups (key, label, position) VALUES (?, ?, (SELECT COALESCE(MAX(position), -1) + 1 FROM voice_groups))')
                ->execute([$group->key, $group->label]);

            return true;
        });
    }

    public function rename(string $key, string $label): void
    {
        $this->pdo->prepare('UPDATE voice_groups SET label = ? WHERE key = ?')->execute([$label, $key]);
    }

    /**
     * Swaps the group with its neighbour above ($step -1) or below ($step 1). At either end of
     * the order nothing changes.
     */
    public function move(string $key, int $step): void
    {
        Transaction::immediate($this->pdo, function () use ($key, $step): void {
            $keys = array_map(static fn(VoiceGroup $group): string => $group->key, $this->all());
            $from = array_search($key, $keys, true);
            if ($from === false || !isset($keys[$from + $step])) {
                return;
            }
            [$keys[$from], $keys[$from + $step]] = [$keys[$from + $step], $keys[$from]];
            $update = $this->pdo->prepare('UPDATE voice_groups SET position = ? WHERE key = ?');
            foreach ($keys as $position => $each) {
                $update->execute([$position, $each]);
            }
        });
    }

    /**
     * Removes a group nobody uses.
     *
     * @return bool false while members or polls still use it
     */
    public function removeUnused(string $key): bool
    {
        return Transaction::immediate($this->pdo, function () use ($key): bool {
            if (in_array($key, $this->keysInUse(), true)) {
                return false;
            }
            $this->pdo->prepare('DELETE FROM voice_groups WHERE key = ?')->execute([$key]);

            return true;
        });
    }

    /**
     * Replaces all groups, in the given order. Fails while a member still belongs to a removed group.
     *
     * @param list<VoiceGroup> $groups
     */
    public function replaceAll(array $groups): void
    {
        $this->pdo->exec('DELETE FROM voice_groups');
        $insert = $this->pdo->prepare('INSERT INTO voice_groups (key, label, position) VALUES (?, ?, ?)');
        foreach ($groups as $position => $group) {
            $insert->execute([$group->key, $group->label, $position]);
        }
    }
}
