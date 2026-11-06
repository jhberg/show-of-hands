<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\DelegationRefusal;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\RollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use ShowOfHands\Tests\TempDirectory;

/**
 * Two writes that compete for one poll. A second process (hold-commit.php) runs the first write
 * and holds the write lock just before its COMMIT. This process starts the second write while
 * that lock is held. BEGIN IMMEDIATE makes it wait for the lock before it reads anything, so it
 * checks the poll as the first write left it.
 */
final class ConcurrencyTest extends TestCase
{
    use TempDirectory;

    public const SECRET = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    /** When the other process closes the poll. */
    public const FIRST_CLOSE = '2026-03-01T19:00:00Z';

    private string $database;

    private \PDO $pdo;

    private PollRepository $polls;

    private BallotRepository $ballots;

    private DelegationRepository $delegations;

    private PollCloser $closer;

    private int $poll;

    /** @var array<string, int> member ids by first name */
    private array $id = [];

    protected function setUp(): void
    {
        $dir = $this->tempDir();
        $this->database = $dir . '/app.sqlite';
        $this->pdo = ConnectionFactory::open($this->database);
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations', $dir))->migrate();
        (new VoiceGroupRepository($this->pdo))->replaceAll([new VoiceGroup('S1', 'Soprano 1')]);
        $members = new MemberRepository($this->pdo);
        foreach (['Ada', 'Ben', 'Cem', 'Dan', 'Eva'] as $name) {
            $this->id[$name] = $members->add("{$name} Example", [strtolower($name) . '@example.org'], 'S1', false, false);
        }
        $this->polls = new PollRepository($this->pdo);
        $this->poll = $this->polls->add(PollKind::Admission, 'Robin Example', null, 'S1', null, '', PollStatus::Open, '2026-01-01T00:00:00Z');
        $this->ballots = new BallotRepository($this->pdo, new BallotToken(self::SECRET));
        $this->delegations = new DelegationRepository($this->pdo, $this->ballots, new SettingsRepository($this->pdo));
        $this->closer = new PollCloser($this->pdo, $this->polls, $this->ballots, $this->delegations, $members, new RollRepository($this->pdo));
    }

    /**
     * Runs $first in the other process up to its COMMIT, then $second here, which has to wait
     * for the lock until the other process commits. The default busy_timeout of 5 s covers the
     * other process's pause.
     *
     * @param list<int|string> $first the action and arguments for hold-commit.php
     */
    private function whileAnotherProcessCommits(array $first, callable $second): void
    {
        $command = [PHP_BINARY, __DIR__ . '/hold-commit.php', $this->database, ...array_map(strval(...), $first)];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            // Reading stderr waits for the other process to end, so only a failed start reads it.
            if (fgets($pipes[1]) !== CommitHoldingPdo::HOLDING . "\n") {
                self::fail('The other process did not reach its commit: ' . stream_get_contents($pipes[2]));
            }
            $second();
        } finally {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'The other process failed: ' . $output);
        }
    }

    private function rowsOfThePoll(string $sql): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$this->poll]);

        return (int) $statement->fetchColumn();
    }

    public function testABallotThatWaitsForTheCloseIsRefused(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ada'], Choice::Yes);

        $this->whileAnotherProcessCommits(['close', $this->poll], function (): void {
            try {
                $this->ballots->cast($this->poll, $this->id['Ben'], Choice::No);
                self::fail('The ballot landed after the close.');
            } catch (PollNotOpenException) {
            }
        });

        $tally = $this->polls->find($this->poll)?->tally;
        self::assertNotNull($tally);
        self::assertSame([1, 0], [$tally->yes, $tally->no]);
        self::assertSame(1, $this->rowsOfThePoll('SELECT COUNT(*) FROM ballots WHERE poll_id = ?'), 'No ballot lands after the tally froze.');
        self::assertSame(1, $this->rowsOfThePoll('SELECT COUNT(*) FROM participation WHERE poll_id = ?'));
    }

    public function testACloseThatWaitsForABallotCountsIt(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ada'], Choice::Yes);

        $this->whileAnotherProcessCommits(['cast', $this->poll, $this->id['Ben'], Choice::No->value], function (): void {
            $this->closer->close($this->poll, new \DateTimeImmutable('2026-03-01T19:00:00Z'));
        });

        $tally = $this->polls->find($this->poll)?->tally;
        self::assertNotNull($tally);
        self::assertSame([1, 1], [$tally->yes, $tally->no]);
    }

    public function testTheSecondOfTwoClosesIsRefused(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ada'], Choice::Yes);

        $this->whileAnotherProcessCommits(['close', $this->poll], function (): void {
            try {
                $this->closer->close($this->poll, new \DateTimeImmutable('2026-03-01T19:00:05Z'));
                self::fail('The poll closed twice.');
            } catch (PollNotOpenException) {
            }
        });

        $poll = $this->polls->find($this->poll);
        self::assertSame(PollStatus::Closed, $poll?->status);
        self::assertSame(self::FIRST_CLOSE, $poll->closedAt);
        self::assertSame(count($this->id), $this->rowsOfThePoll('SELECT COUNT(*) FROM poll_roll WHERE poll_id = ?'), 'The turnout snapshot is written once.');
    }

    /**
     * Eva already holds one vote and the limit is two. Cem and Dan both pick her from a page that
     * still offered her. Whichever write waits for the lock sees her full and is refused.
     */
    public function testTheSecondOfTwoDelegationsToAFullMemberIsRefused(): void
    {
        self::assertSame(2, (new SettingsRepository($this->pdo))->maxReceived());
        self::assertNull($this->delegations->delegate($this->poll, $this->id['Ben'], $this->id['Eva']));

        $this->whileAnotherProcessCommits(['delegate', $this->poll, $this->id['Cem'], $this->id['Eva']], function (): void {
            self::assertSame(DelegationRefusal::TargetFull, $this->delegations->delegate($this->poll, $this->id['Dan'], $this->id['Eva']));
        });

        self::assertSame(
            [$this->id['Ben'] => $this->id['Eva'], $this->id['Cem'] => $this->id['Eva']],
            $this->delegations->forPoll($this->poll)->all(),
        );
    }
}
