<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Database;

use ShowOfHands\Infrastructure\Storage\DataDirectory;

/**
 * Applies the numbered SQL files in migrations/ that the database has not seen yet.
 *
 * The schema version lives in SQLite's user_version, so the check on every request
 * costs one pragma plus a look at the migration files. Before it changes an existing schema,
 * the migrator copies the database to data/backup/, which lazy maintenance deletes 7 days
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
                // Lazy maintenance deletes the backup 7 days after this moment, the successful migration.
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

    /**
     * Stops the release on a database that is ahead of it, see isAheadOfRelease().
     *
     * @throws DatabaseAheadException naming the backup of this release's schema version, if it exists
     */
    public function requireNotAhead(): void
    {
        if (!$this->isAheadOfRelease()) {
            return;
        }
        $backup = self::BACKUP_DIR . '/' . sprintf(self::BACKUP_NAME, max([0, ...array_keys($this->all())]));

        throw new DatabaseAheadException(is_file($this->dataDir . '/' . $backup) ? 'data/' . $backup : null);
    }

    public function version(): int
    {
        return $this->pragma('user_version');
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
        // A migration that rebuilds a table drops the old one, and with foreign keys on, that deletes
        // every row referencing it (ON DELETE CASCADE). SQLite's procedure for schema changes
        // (https://www.sqlite.org/lang_altertable.html#otheralter): foreign keys off outside the
        // transaction, which ignores the pragma, a foreign_key_check before the commit, then on again.
        $foreignKeys = $this->pragma('foreign_keys') === 1;
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $this->pdo->exec($sql);
            if ($foreignKeys) {
                $this->requireIntactForeignKeys();
            }
            // user_version is part of the database header, so it commits or rolls back with the schema.
            $this->pdo->exec('PRAGMA user_version = ' . $number);
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw new \RuntimeException('Migration ' . basename($file) . ' failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
        }
    }

    private function requireIntactForeignKeys(): void
    {
        $statement = $this->pdo->query('PRAGMA foreign_key_check');
        $broken = $statement === false ? [] : $statement->fetchAll();
        if ($broken !== []) {
            throw new \RuntimeException('It leaves ' . count($broken) . ' broken foreign key reference(s).');
        }
    }

    private function pragma(string $name): int
    {
        $statement = $this->pdo->query('PRAGMA ' . $name);
        $value = $statement === false ? 0 : $statement->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return string the backup's path
     */
    private function backup(): string
    {
        $dir = $this->dataDir . '/' . self::BACKUP_DIR;
        DataDirectory::create($dir);
        // One backup per schema version. A migration that keeps failing must not copy the database on every request.
        $target = $dir . '/' . sprintf(self::BACKUP_NAME, $this->version());
        if (is_file($target)) {
            return $target;
        }
        // VACUUM INTO writes a consistent copy even while other connections read.
        $statement = $this->pdo->prepare('VACUUM INTO ?');
        $statement->execute([$target]);
        DataDirectory::restrict($target);

        return $target;
    }
}
