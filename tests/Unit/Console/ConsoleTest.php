<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\Console\MemberAddCommand;
use ShowOfHands\Infrastructure\Console\PurgeCommand;
use ShowOfHands\Infrastructure\Console\SeedDemoCommand;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends TestCase
{
    use TempDirectory;

    private function paths(string $env = 'dev'): Paths
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/config.php', '<?php return ' . var_export([
            'app' => ['url' => 'http://localhost:8080', 'secret' => str_repeat('ab', 32), 'timezone' => 'Europe/Berlin', 'env' => $env],
            'google' => ['client_id' => 'id', 'client_secret' => 'secret'],
            'brand' => ['name' => 'Example Choir'],
        ], true) . ';');

        return new Paths(dirname(__DIR__, 3), $dir . '/data', $dir . '/config.php');
    }

    private function value(Paths $paths, string $sql): mixed
    {
        $statement = ConnectionFactory::open($paths->database())->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    public function testSeedsTheSampleChoir(): void
    {
        $paths = $this->paths();
        $tester = new CommandTester(new SeedDemoCommand($paths));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(Command::SUCCESS, (new CommandTester(new SeedDemoCommand($paths)))->execute([]), 'Seeding twice replaces the data.');

        self::assertSame(25, $this->value($paths, 'SELECT count(*) FROM members'));
        self::assertSame(30, $this->value($paths, 'SELECT count(*) FROM member_emails'));
        self::assertSame(0, $this->value($paths, "SELECT count(*) FROM member_emails WHERE email NOT LIKE '%@example.org' AND email NOT LIKE '%@example.net'"));
        self::assertSame(2, $this->value($paths, 'SELECT count(*) FROM members WHERE is_admin = 1'));
        self::assertSame(3, $this->value($paths, 'SELECT count(*) FROM members WHERE is_board = 1'));
        self::assertSame('rosa.kuehn@example.org', $this->value($paths, "SELECT e.email FROM member_emails e JOIN members m ON m.id = e.member_id WHERE m.name = 'Rosa Kühn'"));
        self::assertSame('L', $this->value($paths, "SELECT voice_group_key FROM members WHERE name = 'Carla Mertens'"));
    }

    public function testRefusesToSeedOutsideDevelopment(): void
    {
        $paths = $this->paths('prod');
        $tester = new CommandTester(new SeedDemoCommand($paths));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString("runs only while app.env is 'dev'", $tester->getDisplay());
    }

    public function testAddsAMember(): void
    {
        $paths = $this->paths('prod');
        $tester = new CommandTester(new MemberAddCommand($paths));

        $status = $tester->execute(['name' => 'Alex Example', 'email' => 'Alex@Example.org', '--email2' => 'alex@example.net', '--group' => 'S1', '--admin' => true, '--board' => true]);

        self::assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        self::assertSame(1, $this->value($paths, "SELECT is_admin FROM members WHERE name = 'Alex Example'"));
        self::assertSame(1, $this->value($paths, "SELECT position FROM member_emails WHERE email = 'alex@example.net'"));
    }

    public function testRejectsAnAddressInUseAndAnUnknownGroup(): void
    {
        $paths = $this->paths('prod');
        (new CommandTester(new MemberAddCommand($paths)))->execute(['name' => 'Alex Example', 'email' => 'alex@example.org']);

        $duplicate = new CommandTester(new MemberAddCommand($paths));
        self::assertSame(Command::FAILURE, $duplicate->execute(['name' => 'Sam Example', 'email' => 'ALEX@example.org']));
        $group = new CommandTester(new MemberAddCommand($paths));
        self::assertSame(Command::INVALID, $group->execute(['name' => 'Sam Example', 'email' => 'sam@example.org', '--group' => 'X9']));
        self::assertStringContainsString('Known keys: L, S1', $group->getDisplay());
        self::assertSame(1, $this->value($paths, 'SELECT count(*) FROM members'));
    }

    public function testPurgeRunsTheMaintenance(): void
    {
        $tester = new CommandTester(new PurgeCommand($this->paths()));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Deleted 0 expired session files, 0 old backups, and 0 ballots of closed polls.', $tester->getDisplay());
    }
}
