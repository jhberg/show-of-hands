<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Maintenance;

use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;

/**
 * Housekeeping that hosts without cron cannot schedule. A request runs it at most once an hour.
 *
 * - Deletes session files older than the session lifetime, because many hosts switch off
 *   PHP's own session garbage collection.
 * - Deletes the pre-migration backup 24 hours after the migration succeeded. It is the only
 *   copy of the database the app makes, and later ballots must not live on in it.
 * - Deletes the ballots of closed polls once their close day has ended in the choir's time
 *   zone. Until then an admin may reopen the poll; afterwards the frozen tally is all it needs.
 */
final readonly class Maintenance
{
    public const INTERVAL_SECONDS = 3600;
    public const BACKUP_KEEP_SECONDS = self::DAY;

    private const DAY = 86400;

    public function __construct(
        private SettingsRepository $settings,
        private Migrator $migrator,
        private BallotRepository $ballots,
        private Clock $clock,
        private string $sessionsDir,
        private string $backupDir,
        private int $sessionLifetimeDays,
        private string $timezone,
    ) {}

    /**
     * Runs the job when the last run is at least an hour old.
     */
    public function runIfDue(): void
    {
        if ($this->settings->claimMaintenance($this->clock->now()->getTimestamp(), self::INTERVAL_SECONDS)) {
            $this->run();
        }
    }

    /**
     * @return array{sessions: int, backups: int, ballots: int} how many files and ballots it deleted
     */
    public function run(): array
    {
        $now = $this->clock->now();
        $ballots = $this->ballots->purgeClosed($now, new \DateTimeZone($this->timezone));
        $sessions = $this->deleteOlderThan(
            $this->sessionsDir . '/sess_*',
            $now->getTimestamp() - $this->sessionLifetimeDays * self::DAY,
        );
        // While a migration is pending, it failed, and the backup is the way back.
        $backups = $this->migrator->pending() === []
            ? $this->deleteOlderThan($this->backupDir . '/' . Migrator::BACKUP_GLOB, $now->getTimestamp() - self::BACKUP_KEEP_SECONDS)
            : 0;

        return ['sessions' => $sessions, 'backups' => $backups, 'ballots' => $ballots];
    }

    private function deleteOlderThan(string $pattern, int $cutoff): int
    {
        $deleted = 0;
        foreach (glob($pattern) ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }
}
