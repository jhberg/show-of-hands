<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * The settings admins change in the admin area, stored as key and value.
 */
final readonly class SettingsRepository
{
    public function __construct(private \PDO $pdo) {}

    public function retentionDays(): int
    {
        return (int) $this->get('retention_days');
    }

    /** Whether ordinary members see the names of those who have not voted. Board and admins always do. */
    public function showOpenNames(): bool
    {
        return $this->get('show_open_names') === '1';
    }

    /** Whether members may hand their vote to another member. Switching it off keeps existing delegations. */
    public function delegationEnabled(): bool
    {
        return $this->get('delegation_enabled') === '1';
    }

    /** How many extra votes one member may hold, 1 to 3. */
    public function maxReceived(): int
    {
        return (int) $this->get('max_received');
    }

    /**
     * Claims the maintenance run for this request when the last one is at least $interval seconds old.
     * The claim is one conditional UPDATE, so two parallel requests never both run it. A plain read
     * comes first, because an UPDATE takes SQLite's write lock even when it matches no row.
     */
    public function claimMaintenance(int $now, int $interval): bool
    {
        if ((int) $this->get('maintenance_last_run') > $now - $interval) {
            return false;
        }
        $statement = $this->pdo->prepare(
            "UPDATE settings SET value = ? WHERE key = 'maintenance_last_run' AND CAST(value AS INTEGER) <= ?",
        );
        $statement->execute([(string) $now, $now - $interval]);

        return $statement->rowCount() === 1;
    }

    private function get(string $key): string
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Setting {$key} is missing.");
        }

        return $value;
    }
}
