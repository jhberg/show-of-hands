<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\VoiceGroup;

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

    public function exists(string $key): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM voice_groups WHERE key = ?');
        $statement->execute([$key]);

        return $statement->fetchColumn() !== false;
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
