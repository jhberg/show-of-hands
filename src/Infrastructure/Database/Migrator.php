<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Database;

/**
 * Applies the numbered SQL files in migrations/ that the database has not seen yet.
 *
 * The schema version lives in SQLite's user_version, so the check on every request
 * costs one pragma plus a look at the migration files. Before it changes an existing schema,
 * the migrator copies the database to data/backup/, which lazy maintenance deletes a day
 * after the migration succeeded. A file lock keeps two requests from migrating at the same time.
 *
 * A release ZIP lists its migrations in its MIGRATIONS.txt file, and the migrator runs only those.
 * Unpacking an older release over a newer one, to go back after a failed update, leaves the
 * newer migration files behind. Without the list, the older release would run them again, and a
 * fixed release could meet a broken file under the number of its fixed one.
 */
final class Migrator
{
    public const BACKUP_DIR = 'backup';
    /** One backup per schema version, named after the version it holds. */
    public const BACKUP_GLOB = 'app-v*.sqlite';
    private const BACKUP_NAME = 'app-v%d.sqlite';

    private const FILE_PATTERN = '/^(\d{4})_[a-z0-9_]+\.sql$/';

    /**
     * @param ?list<string> $releaseMigrations the file names the release lists, or null in a git
     *                                         checkout, which runs every migration in the folder
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $migrationsDir,
        private readonly string $dataDir,
        private readonly ?array $releaseMigrations = null,
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
            $backup = $this->version() > 0 ? $this->backup() : null;
            foreach ($pending as $number => $file) {
                $this->apply($number, $file);
            }
            if ($backup !== null) {
                // Lazy maintenance deletes the backup 24 hours after this moment, the successful migration.
                touch($backup);
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

    /**
     * Whether the database holds migrations this release does not list: an older release was
     * unpacked over a newer one, and the backup is still the way back.
     *
     * @phpstan-impure
     */
    public function isAheadOfRelease(): bool
    {
        $numbers = array_keys($this->all());

        return $this->releaseMigrations !== null && $this->version() > max([0, ...$numbers]);
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
        foreach ($this->releaseMigrations ?? (scandir($this->migrationsDir) ?: []) as $name) {
            if (preg_match(self::FILE_PATTERN, $name, $m) !== 1) {
                continue;
            }
            $number = (int) $m[1];
            if (isset($files[$number])) {
                throw new \RuntimeException("Two migrations share the number {$m[1]}.");
            }
            $file = $this->migrationsDir . '/' . $name;
            if (!is_file($file)) {
                throw new \RuntimeException("The release lists {$name}, but migrations/ has no such file.");
            }
            $files[$number] = $file;
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

    /**
     * @return string the backup's path
     */
    private function backup(): string
    {
        $dir = $this->dataDir . '/' . self::BACKUP_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }
        // One backup per schema version. A migration that keeps failing must not copy the database on every request.
        $target = $dir . '/' . sprintf(self::BACKUP_NAME, $this->version());
        if (is_file($target)) {
            return $target;
        }
        // VACUUM INTO writes a consistent copy even while other connections read.
        $statement = $this->pdo->prepare('VACUUM INTO ?');
        $statement->execute([$target]);
        chmod($target, 0o600);

        return $target;
    }
}
