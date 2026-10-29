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

    private function migrator(\PDO $pdo): Migrator
    {
        return new Migrator($pdo, $this->dir . '/migrations', $this->dir);
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

    public function testRefusesTwoMigrationsWithTheSameNumber(): void
    {
        $this->migration('0001_a.sql', 'SELECT 1;');
        $this->migration('0001_b.sql', 'SELECT 1;');

        $this->expectExceptionMessage('Two migrations share the number 0001.');
        $this->migrator(ConnectionFactory::open($this->dir . '/app.sqlite'))->migrate();
    }
}
