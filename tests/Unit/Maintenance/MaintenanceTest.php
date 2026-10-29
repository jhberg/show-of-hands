<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Maintenance;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Tests\TempDirectory;

final class MaintenanceTest extends TestCase
{
    use TempDirectory;

    private const NOW = 1_800_000_000;

    private string $dir;

    private Migrator $migrator;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir($this->dir . '/sessions');
        mkdir($this->dir . '/backup');
        $this->pdo = ConnectionFactory::open($this->dir . '/app.sqlite');
        $this->migrator = new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations', $this->dir);
        $this->migrator->migrate();
    }

    private function maintenance(int $now = self::NOW): Maintenance
    {
        $clock = new class ($now) implements Clock {
            public function __construct(private readonly int $now) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@' . $this->now);
            }
        };

        return new Maintenance(new SettingsRepository($this->pdo), $this->migrator, $clock, $this->dir . '/sessions', $this->dir . '/backup', 30);
    }

    private function file(string $path, int $mtime): string
    {
        $file = $this->dir . '/' . $path;
        file_put_contents($file, 'x');
        touch($file, $mtime);

        return $file;
    }

    public function testDeletesSessionsOlderThanTheLifetime(): void
    {
        $expired = $this->file('sessions/sess_old', self::NOW - 31 * 86400);
        $active = $this->file('sessions/sess_new', self::NOW - 29 * 86400);
        $other = $this->file('sessions/notes.txt', self::NOW - 400 * 86400);

        self::assertSame(['sessions' => 1, 'backups' => 0], $this->maintenance()->run());

        self::assertFileDoesNotExist($expired);
        self::assertFileExists($active);
        self::assertFileExists($other, 'Only session files are deleted.');
    }

    public function testDeletesTheBackupADayAfterTheMigration(): void
    {
        $old = $this->file('backup/app-v1.sqlite', self::NOW - Maintenance::BACKUP_KEEP_SECONDS - 1);
        $fresh = $this->file('backup/app-v2.sqlite', self::NOW - Maintenance::BACKUP_KEEP_SECONDS + 60);

        self::assertSame(['sessions' => 0, 'backups' => 1], $this->maintenance()->run());

        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
    }

    public function testKeepsTheBackupWhileAMigrationIsPending(): void
    {
        $backup = $this->file('backup/app-v1.sqlite', self::NOW - 7 * 86400);
        $migrations = $this->tempDir() . '/pending';
        mkdir($migrations);
        copy(dirname(__DIR__, 3) . '/migrations/0001_init.sql', $migrations . '/0001_init.sql');
        file_put_contents($migrations . '/0099_broken.sql', 'INSERT INTO missing VALUES (1);');
        $this->migrator = new Migrator($this->pdo, $migrations, $this->dir);

        $this->maintenance()->run();

        self::assertFileExists($backup);
    }

    public function testRunsAtMostOnceAnHour(): void
    {
        $session = fn(): string => $this->file('sessions/sess_' . bin2hex(random_bytes(4)), 0);

        $first = $session();
        $this->maintenance()->runIfDue();
        self::assertFileDoesNotExist($first);

        $second = $session();
        $this->maintenance(self::NOW + Maintenance::INTERVAL_SECONDS - 1)->runIfDue();
        self::assertFileExists($second);

        $this->maintenance(self::NOW + Maintenance::INTERVAL_SECONDS)->runIfDue();
        self::assertFileDoesNotExist($second);
    }
}
