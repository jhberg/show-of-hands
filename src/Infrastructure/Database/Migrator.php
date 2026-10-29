<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Database;

/**
 * Applies the numbered SQL files in migrations/ that the database has not seen yet.
 *
 * The schema version lives in SQLite's user_version, so the check on every request
 * costs one directory listing and one pragma. Before it changes an existing schema,
 * the migrator copies the database to data/backup/. A file lock keeps two requests
 * from migrating at the same time.
 */
final class Migrator
{
    private const FILE_PATTERN = '/^(\d{4})_[a-z0-9_]+\.sql$/';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
        private readonly string $dataDir,
    ) {}

    /**
     * @return int how many migrations ran
     */
    public function migrate(): int
    {
        if ($this->pending() === []) {
            return 0;
        }

        $lock = fopen($this->dataDir . '/migrate.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock data/migrate.lock.');
        }
        try {
            // Another request may have finished the work while this one waited for the lock.
            $pending = $this->pending();
            if ($pending === []) {
                return 0;
            }
            if ($this->version() > 0) {
                $this->backup();
            }
            foreach ($pending as $number => $file) {
                $this->apply($number, $file);
            }

            return count($pending);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<int, string> migration number => file path, ascending
     *
     * @phpstan-impure
     */
    public function pending(): array
    {
        $version = $this->version();

        return array_filter($this->all(), static fn(int $number): bool => $number > $version, ARRAY_FILTER_USE_KEY);
    }

    public function version(): int
    {
        $statement = $this->pdo->query('PRAGMA user_version');
        $version = $statement === false ? 0 : $statement->fetchColumn();

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * @return array<int, string>
     */
    private function all(): array
    {
        $files = [];
        foreach (scandir($this->migrationsDir) ?: [] as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $m) !== 1) {
                continue;
            }
            $number = (int) $m[1];
            if (isset($files[$number])) {
                throw new \RuntimeException("Two migrations share the number {$m[1]}.");
            }
            $files[$number] = $this->migrationsDir . '/' . $name;
        }
        ksort($files);

        return $files;
    }

    private function apply(int $number, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read {$file}.");
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->exec($sql);
            // user_version is part of the database header, so it commits or rolls back with the schema.
            $this->pdo->exec('PRAGMA user_version = ' . $number);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw new \RuntimeException('Migration ' . basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function backup(): void
    {
        $dir = $this->dataDir . '/backup';
        if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
        // One backup per schema version. A migration that keeps failing must not copy the database on every request.
        $target = sprintf('%s/app-v%d.sqlite', $dir, $this->version());
        if (is_file($target)) {
            return;
        }
        // VACUUM INTO writes a consistent copy even while other connections read.
        $statement = $this->pdo->prepare('VACUUM INTO ?');
        $statement->execute([$target]);
        chmod($target, 0o600);
    }
}
