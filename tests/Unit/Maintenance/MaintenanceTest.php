<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Maintenance;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\Majority;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\Tally;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Tests\FixedClock;
use ShowOfHands\Tests\TempDirectory;

final class MaintenanceTest extends TestCase
{
    use TempDirectory;

    /** 2027-01-15, 09:00 in Berlin. */
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
        $clock = FixedClock::at('@' . $now);

        return new Maintenance(new SettingsRepository($this->pdo), $this->migrator, $this->ballots(), new PollRepository($this->pdo), $clock, $this->dir . '/sessions', $this->dir . '/backup', $this->dir . '/cache/1.1.0', $this->dir . '/app.sqlite', 30, 'Europe/Berlin');
    }

    private function ballots(): BallotRepository
    {
        return new BallotRepository($this->pdo, new BallotToken(str_repeat('ab', 32)));
    }

    /**
     * A poll with one ballot, closed at the given time, or open when that is null.
     */
    private function pollWithBallot(?string $closedAt): int
    {
        $this->pdo->exec("INSERT OR IGNORE INTO voice_groups (key, label, position) VALUES ('S1', 'Soprano 1', 0)");
        $this->pdo->exec("INSERT OR IGNORE INTO members (id, name, voice_group_key, is_board, is_admin) VALUES (1, 'Kim Example', 'S1', 0, 0)");
        $polls = new PollRepository($this->pdo);
        $poll = $polls->add(PollKind::Admission, 'Robin Example', null, 'S1', null, '', PollStatus::Open, '2027-01-01T00:00:00Z');
        $this->ballots()->cast($poll, 1, Choice::Yes);
        if ($closedAt !== null) {
            $polls->markClosed($poll, new \DateTimeImmutable($closedAt), new Tally(1, 1, 0, 0, Majority::default()));
        }

        return $poll;
    }

    private function hasBallots(int $poll): bool
    {
        $statement = $this->pdo->prepare('SELECT EXISTS (SELECT 1 FROM ballots WHERE poll_id = ?)');
        $statement->execute([$poll]);

        return $statement->fetchColumn() === 1;
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

        self::assertSame(['sessions' => 1, 'backups' => 0, 'ballots' => 0, 'polls' => 0], $this->maintenance()->run());

        self::assertFileDoesNotExist($expired);
        self::assertFileExists($active);
        self::assertFileExists($other, 'Only session files are deleted.');
    }

    public function testDeletesTheBackupAWeekAfterTheMigration(): void
    {
        $old = $this->file('backup/app-v1.sqlite', self::NOW - Maintenance::BACKUP_KEEP_SECONDS - 1);
        $fresh = $this->file('backup/app-v2.sqlite', self::NOW - Maintenance::BACKUP_KEEP_SECONDS + 60);
        self::assertSame(7 * 86400, Maintenance::BACKUP_KEEP_SECONDS);

        self::assertSame(['sessions' => 0, 'backups' => 1, 'ballots' => 0, 'polls' => 0], $this->maintenance()->run());

        self::assertFileDoesNotExist($old);
        self::assertFileExists($fresh);
    }

    public function testRestrictsADatabaseAnEarlierReleaseCreated(): void
    {
        chmod($this->dir . '/app.sqlite', 0o644);

        $this->maintenance()->run();

        self::assertSame(0o600, fileperms($this->dir . '/app.sqlite') & 0o777);
    }

    public function testDeletesTheCachesOfOtherReleases(): void
    {
        $old = [];
        foreach (['1.0.0/twig/ab/template.php', '1.0.0/translations/catalogue.de.php', '1.0.0/container/CompiledContainer.php', 'dev/twig/ab/template.php', 'twig/1.0.0/ab/template.php'] as $file) {
            mkdir(dirname($this->dir . '/cache/' . $file), 0o777, true);
            $old[] = $this->file('cache/' . $file, self::NOW);
        }
        $current = [];
        foreach (['1.1.0/twig/cd/template.php', '1.1.0/translations/catalogue.de.php', '1.1.0/container/CompiledContainer.php'] as $file) {
            mkdir(dirname($this->dir . '/cache/' . $file), 0o777, true);
            $current[] = $this->file('cache/' . $file, self::NOW);
        }

        $this->maintenance()->run();

        // The last one is where 0.1.0 compiled its templates.
        self::assertSame(['1.1.0'], array_map(basename(...), glob($this->dir . '/cache/*') ?: []));
        foreach ($old as $file) {
            self::assertFileDoesNotExist($file);
        }
        foreach ($current as $file) {
            self::assertFileExists($file);
        }
    }

    public function testDeletesTheBallotsOfPollsWhoseCloseDayHasEnded(): void
    {
        // 23:30 in Berlin the evening before, 08:00 this morning, and still open.
        $yesterday = $this->pollWithBallot('2027-01-14T22:30:00Z');
        $today = $this->pollWithBallot('2027-01-15T07:00:00Z');
        $open = $this->pollWithBallot(null);

        self::assertSame(['sessions' => 0, 'backups' => 0, 'ballots' => 1, 'polls' => 0], $this->maintenance()->run());

        self::assertFalse($this->hasBallots($yesterday));
        self::assertTrue($this->hasBallots($today), 'An admin may still reopen it today.');
        self::assertTrue($this->hasBallots($open));
    }

    public function testDeletesClosedPollsOnceTheirRetentionHasPassed(): void
    {
        // The default retention is 14 days. NOW is 2027-01-15T08:00:00Z.
        $expired = $this->pollWithBallot('2027-01-01T07:59:59Z');
        $kept = $this->pollWithBallot('2027-01-01T08:00:01Z');
        $open = $this->pollWithBallot(null);
        $this->pdo->exec("INSERT INTO poll_roll (poll_id, member_id, name, status) VALUES ({$expired}, 1, 'Kim Example', 'voted')");

        self::assertSame(['sessions' => 0, 'backups' => 0, 'ballots' => 1, 'polls' => 1], $this->maintenance()->run());

        $polls = new PollRepository($this->pdo);
        self::assertNull($polls->find($expired));
        self::assertNotNull($polls->find($kept));
        self::assertNotNull($polls->find($open));
        foreach (['ballots', 'participation', 'poll_roll'] as $table) {
            $count = $this->pdo->prepare("SELECT count(*) FROM {$table} WHERE poll_id = ?");
            $count->execute([$expired]);
            self::assertSame(0, $count->fetchColumn(), $table);
        }
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

    public function testKeepsTheBackupWhileAnOlderReleaseRunsOnTheNewerDatabase(): void
    {
        $backup = $this->file('backup/app-v1.sqlite', self::NOW - 7 * 86400);
        // The database came this far with the failed update; the release unpacked since lists less.
        $this->migrator = new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations', $this->dir, ['0001_init.sql']);

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
