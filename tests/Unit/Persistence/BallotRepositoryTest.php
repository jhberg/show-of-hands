<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\CastOutcome;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use ShowOfHands\Tests\TempDirectory;

final class BallotRepositoryTest extends TestCase
{
    use TempDirectory;

    private const SECRET = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    private \PDO $pdo;

    private BallotRepository $ballots;

    private PollRepository $polls;

    private int $poll;

    private int $kim;

    private int $lou;

    protected function setUp(): void
    {
        $dir = $this->tempDir();
        $this->pdo = ConnectionFactory::open($dir . '/app.sqlite');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations', $dir))->migrate();
        (new VoiceGroupRepository($this->pdo))->replaceAll([new VoiceGroup('S1', 'Soprano 1')]);
        $members = new MemberRepository($this->pdo);
        $this->kim = $members->add('Kim Example', ['kim@example.org'], 'S1', false, false);
        $this->lou = $members->add('Lou Example', ['lou@example.org'], 'S1', false, false);
        $this->polls = new PollRepository($this->pdo);
        $this->poll = $this->addPoll(PollStatus::Open);
        $this->ballots = new BallotRepository($this->pdo, new BallotToken(self::SECRET));
    }

    private function addPoll(PollStatus $status): int
    {
        return $this->polls->add(PollKind::Admission, 'Robin Example', null, 'S1', null, '', $status, '2026-01-01T00:00:00Z');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    public function testCastsChangesAndKeepsTheMembersVote(): void
    {
        self::assertNull($this->ballots->choice($this->poll, $this->kim));

        self::assertSame(CastOutcome::Registered, $this->ballots->cast($this->poll, $this->kim, Choice::Yes)->outcome);
        self::assertSame(Choice::Yes, $this->ballots->choice($this->poll, $this->kim));

        self::assertSame(CastOutcome::Changed, $this->ballots->cast($this->poll, $this->kim, Choice::No)->outcome);
        self::assertSame(Choice::No, $this->ballots->choice($this->poll, $this->kim));

        self::assertSame(CastOutcome::Unchanged, $this->ballots->cast($this->poll, $this->kim, Choice::No)->outcome);
        self::assertNull($this->ballots->choice($this->poll, $this->lou));
        self::assertSame([$this->kim], $this->ballots->participantIds($this->poll));
    }

    public function testStoresOnlyTheTokenAndTheChoice(): void
    {
        $this->ballots->cast($this->poll, $this->kim, Choice::Yes);
        $this->ballots->cast($this->poll, $this->lou, Choice::No);

        $rows = $this->rows('SELECT poll_id, token, typeof(token) AS type, choice FROM ballots ORDER BY choice DESC');
        $tokens = new BallotToken(self::SECRET);
        self::assertSame([
            ['poll_id' => $this->poll, 'token' => $tokens->for($this->poll, $this->kim), 'type' => 'blob', 'choice' => 'yes'],
            ['poll_id' => $this->poll, 'token' => $tokens->for($this->poll, $this->lou), 'type' => 'blob', 'choice' => 'no'],
        ], $rows);
        $columns = array_column($this->rows('PRAGMA table_info(ballots)'), 'name');
        self::assertSame(['poll_id', 'token', 'choice'], $columns);
    }

    public function testRefusesABallotForAPollThatIsNotOpen(): void
    {
        foreach ([PollStatus::Draft, PollStatus::Closed] as $status) {
            $poll = $this->addPoll($status);
            try {
                $this->ballots->cast($poll, $this->kim, Choice::Yes);
                self::fail("Cast a ballot in a {$status->value} poll.");
            } catch (PollNotOpenException) {
                self::assertSame([], $this->ballots->participantIds($poll));
            }
        }
        $this->expectException(PollNotOpenException::class);
        $this->ballots->cast(999, $this->kim, Choice::Yes);
    }

    public function testFindsThePollsAMemberVotedIn(): void
    {
        $other = $this->addPoll(PollStatus::Open);
        $this->ballots->cast($other, $this->kim, Choice::No);

        self::assertSame([$other], $this->ballots->pollsVotedIn($this->kim, [$this->poll, $other]));
        self::assertSame([], $this->ballots->pollsVotedIn($this->kim, [$this->poll]));
        self::assertSame([], $this->ballots->pollsVotedIn($this->kim, []));
    }
}
