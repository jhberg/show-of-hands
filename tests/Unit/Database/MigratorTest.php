<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Tests\TempDirectory;

final class MigratorTest extends TestCase
{
    use TempDirectory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir($this->dir . '/migrations');
    }

    private function migration(string $name, string $sql): void
    {
        file_put_contents("{$this->dir}/migrations/{$name}", $sql);
    }

    private static function scalar(\PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /**
     * @param ?list<string> $releaseMigrations
     */
    private function migrator(\PDO $pdo, ?array $releaseMigrations = null): Migrator
    {
        return new Migrator($pdo, $this->dir . '/migrations', $this->dir, $releaseMigrations);
    }

    public function testOpensConnectionsWithThePragmas(): void
    {
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');

        self::assertSame(1, self::scalar($pdo, 'PRAGMA secure_delete'));
        self::assertSame(1, self::scalar($pdo, 'PRAGMA foreign_keys'));
        self::assertSame(5000, self::scalar($pdo, 'PRAGMA busy_timeout'));
    }

    public function testAppliesPendingMigrationsInOrder(): void
    {
        $this->migration('0002_add.sql', 'INSERT INTO t VALUES (2);');
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER); INSERT INTO t VALUES (1);');
        $this->migration('README.md', 'not a migration');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');

        self::assertSame(2, $this->migrator($pdo)->migrate());
        self::assertSame(2, $this->migrator($pdo)->version());
        $statement = $pdo->query('SELECT n FROM t ORDER BY rowid');
        self::assertNotFalse($statement);
        self::assertSame([1, 2], $statement->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(0, $this->migrator($pdo)->migrate());
    }

    public function testBacksUpAnExistingDatabaseBeforeUpdating(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');
        $this->migrator($pdo)->migrate();
        self::assertDirectoryDoesNotExist($this->dir . '/backup', 'A fresh database needs no backup.');

        $this->migration('0002_more.sql', 'CREATE TABLE u (n INTEGER);');
        $this->migrator($pdo)->migrate();

        $backups = glob($this->dir . '/backup/app-v1.sqlite') ?: [];
        self::assertCount(1, $backups);
        $copy = new \PDO('sqlite:' . $backups[0]);
        self::assertSame(1, self::scalar($copy, 'PRAGMA user_version'));
    }

    public function testDatesTheBackupToTheSuccessfulMigration(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');
        $this->migrator($pdo)->migrate();
        $this->migration('0002_broken.sql', 'INSERT INTO missing VALUES (1);');
        try {
            $this->migrator($pdo)->migrate();
        } catch (\RuntimeException) {
        }
        $backup = $this->dir . '/backup/app-v1.sqlite';
        touch($backup, time() - 7 * 86400);

        $this->migration('0002_broken.sql', 'CREATE TABLE u (n INTEGER);');
        $this->migrator($pdo)->migrate();

        clearstatcache();
        self::assertGreaterThan(time() - 60, (int) filemtime($backup), 'Maintenance keeps the backup for 7 days after this success.');
    }

    public function testRollsBackAFailedMigration(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');
        $this->migration('0002_broken.sql', 'CREATE TABLE u (n INTEGER); INSERT INTO missing VALUES (1);');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');

        try {
            $this->migrator($pdo)->migrate();
            self::fail('The broken migration was accepted.');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('Migration 0002_broken.sql failed', $e->getMessage());
        }
        self::assertSame(1, $this->migrator($pdo)->version());
        try {
            $this->migrator($pdo)->migrate();
        } catch (\RuntimeException) {
        }
        self::assertCount(1, glob($this->dir . '/backup/*.sqlite') ?: [], 'A retried migration reuses the backup.');
        self::assertFalse(self::scalar($pdo, "SELECT 1 FROM sqlite_master WHERE name = 'u'"));
    }

    public function testRebuildingATableKeepsTheRowsThatReferenceIt(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE parent (id INTEGER PRIMARY KEY, label TEXT);
            CREATE TABLE child (parent_id INTEGER NOT NULL REFERENCES parent (id) ON DELETE CASCADE);
            INSERT INTO parent VALUES (1, \'a\'); INSERT INTO child VALUES (1);');
        $this->migration('0002_rebuild.sql', 'CREATE TABLE parent_new (id INTEGER PRIMARY KEY, label TEXT NOT NULL);
            INSERT INTO parent_new SELECT id, label FROM parent;
            DROP TABLE parent;
            ALTER TABLE parent_new RENAME TO parent;');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');

        $this->migrator($pdo)->migrate();

        self::assertSame(1, self::scalar($pdo, 'SELECT COUNT(*) FROM child'), 'Dropping the old table cascaded.');
        self::assertSame(1, self::scalar($pdo, 'PRAGMA foreign_keys'));
        $pdo->exec('DELETE FROM parent');
        self::assertSame(0, self::scalar($pdo, 'SELECT COUNT(*) FROM child'), 'The rebuilt table cascades again.');
    }

    public function testRefusesAMigrationThatBreaksAForeignKey(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE parent (id INTEGER PRIMARY KEY);
            CREATE TABLE child (parent_id INTEGER NOT NULL REFERENCES parent (id));
            INSERT INTO parent VALUES (1); INSERT INTO child VALUES (1);');
        $this->migration('0002_orphan.sql', 'DELETE FROM parent;');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');

        try {
            $this->migrator($pdo)->migrate();
            self::fail('The migration left a child without its parent.');
        } catch (\RuntimeException $e) {
            self::assertSame('Migration 0002_orphan.sql failed: It leaves 1 broken foreign key reference(s).', $e->getMessage());
        }
        self::assertSame(1, $this->migrator($pdo)->version());
        self::assertSame(1, self::scalar($pdo, 'SELECT COUNT(*) FROM parent'));
        self::assertSame(1, self::scalar($pdo, 'PRAGMA foreign_keys'));
    }

    public function testARolledBackReleaseLeavesTheNewerMigrationsAlone(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');
        // Left behind by a failed update, after the previous release was unpacked over it.
        $this->migration('0002_broken.sql', 'INSERT INTO missing VALUES (1);');
        $migrator = $this->migrator(ConnectionFactory::open($this->dir . '/app.sqlite'), ['0001_init.sql']);

        self::assertSame(1, $migrator->migrate());
        self::assertSame([], $migrator->pending());
        self::assertSame(0, $migrator->migrate());
        self::assertSame(1, $migrator->version());
        self::assertFalse($migrator->isAheadOfRelease());
    }

    public function testAFixedReleaseIgnoresTheBrokenFileUnderItsNumber(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');
        $this->migration('0002_broken.sql', 'INSERT INTO missing VALUES (1);');
        $this->migration('0002_fixed.sql', 'INSERT INTO t VALUES (2);');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');

        self::assertSame(2, $this->migrator($pdo, ['0001_init.sql', '0002_fixed.sql'])->migrate());
        self::assertSame(2, self::scalar($pdo, 'SELECT n FROM t'));
    }

    public function testKnowsWhenTheDatabaseIsAheadOfTheRelease(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');
        $this->migration('0002_more.sql', 'CREATE TABLE u (n INTEGER);');
        $pdo = ConnectionFactory::open($this->dir . '/app.sqlite');
        $this->migrator($pdo)->migrate();

        self::assertTrue($this->migrator($pdo, ['0001_init.sql'])->isAheadOfRelease());
        self::assertFalse($this->migrator($pdo, ['0001_init.sql', '0002_more.sql'])->isAheadOfRelease());
        self::assertFalse($this->migrator($pdo)->isAheadOfRelease(), 'A checkout runs whatever it has.');
    }

    public function testRefusesAListedMigrationThatIsMissing(): void
    {
        $this->migration('0001_init.sql', 'CREATE TABLE t (n INTEGER);');

        $this->expectExceptionMessage('The release lists 0002_more.sql, but migrations/ has no such file.');
        $this->migrator(ConnectionFactory::open($this->dir . '/app.sqlite'), ['0001_init.sql', '0002_more.sql'])->migrate();
    }

    public function testRefusesTwoMigrationsWithTheSameNumber(): void
    {
        $this->migration('0001_a.sql', 'SELECT 1;');
        $this->migration('0001_b.sql', 'SELECT 1;');

        $this->expectExceptionMessage('Two migrations share the number 0001.');
        $this->migrator(ConnectionFactory::open($this->dir . '/app.sqlite'))->migrate();
    }
}
