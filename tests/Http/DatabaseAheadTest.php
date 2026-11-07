<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use ShowOfHands\Paths;

/**
 * An older release unpacked over a newer one, to go back after an update, without the backup
 * moved over the database.
 */
final class DatabaseAheadTest extends HttpTestCase
{
    /** The schema version of the older release. */
    private int $olderVersion;

    protected function setUp(): void
    {
        parent::setUp();
        // The newer release migrated the database. The older one lists one migration fewer.
        $this->addMember('Robin Example', ['robin@example.org']);
        $migrations = array_values(array_filter(
            scandir(dirname(__DIR__, 2) . '/migrations') ?: [],
            static fn(string $name): bool => str_ends_with($name, '.sql'),
        ));
        $older = array_slice($migrations, 0, -1);
        $this->app = $this->createApp(overrides: [
            Migrator::class => static fn(\PDO $pdo, Paths $paths): Migrator => new Migrator($pdo, $paths->migrations(), $paths->data(), $older),
        ]);
        $this->olderVersion = count($older);
    }

    public function testEveryPageNamesTheBackupToMove(): void
    {
        $backup = $this->backup($this->olderVersion);

        foreach (['/login', '/', '/dev-login'] as $path) {
            $response = $this->request('GET', $path);

            self::assertSame(503, $response->getStatusCode(), $path);
            $html = $this->body($response);
            self::assertStringContainsString('Datenbank passt nicht zum Release', $html);
            self::assertStringContainsString(
                "Die Datenbank ist neuer als dieses Release. Verschiebe data/backup/app-v{$this->olderVersion}.sqlite nach data/app.sqlite oder installiere das neuere Release wieder.",
                $html,
            );
        }
        self::assertSame(503, $this->post('/dev-login', ['email' => 'robin@example.org'])->getStatusCode());
        self::assertFileExists($backup);
        self::assertFileDoesNotExist($this->dataDir . '/logs/app.log', 'Every request meets it, so it stays out of the log.');
    }

    public function testWithoutABackupAsksForTheNewerReleaseOrTheOwnCopy(): void
    {
        $response = $this->request('GET', '/login');

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('und in data/backup/ liegt keine passende Kopie.', $this->body($response));
    }

    public function testRunsNoMaintenance(): void
    {
        $backup = $this->backup($this->olderVersion);
        touch($backup, time() - Maintenance::BACKUP_KEEP_SECONDS - 60);
        $this->service(\PDO::class)->exec("UPDATE settings SET value = '0' WHERE key = 'maintenance_last_run'");

        $this->request('GET', '/login');

        self::assertFileExists($backup);
    }

    public function testTheStylesheetStillLoads(): void
    {
        self::assertSame(200, $this->request('GET', ThemeAction::PATH)->getStatusCode());
    }

    public function testMovingTheBackupOverTheDatabaseBringsTheSiteBack(): void
    {
        $backup = $this->backup($this->olderVersion);
        // What moving the backup over the database leaves: a database at the older release's version.
        $this->service(\PDO::class)->exec('PRAGMA user_version = ' . $this->olderVersion);

        self::assertSame(200, $this->request('GET', '/login')->getStatusCode());
        self::assertFileExists($backup);
    }

    /**
     * A copy of the database where the migrator puts it, named after the schema version it holds.
     */
    private function backup(int $version): string
    {
        $file = $this->dataDir . '/' . Migrator::BACKUP_DIR . "/app-v{$version}.sqlite";
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o700);
        }
        copy($this->dataDir . '/app.sqlite', $file);

        return $file;
    }
}
