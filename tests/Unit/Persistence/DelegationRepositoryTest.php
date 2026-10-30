<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\CastOutcome;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\DelegationRefusal;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\Turnout;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use ShowOfHands\Tests\TempDirectory;

final class DelegationRepositoryTest extends TestCase
{
    use TempDirectory;

    private const SECRET = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    private string $database;

    private \PDO $pdo;

    private BallotRepository $ballots;

    private DelegationRepository $delegations;

    private PollRepository $polls;

    private MemberRepository $members;

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
        $this->members = new MemberRepository($this->pdo);
        foreach (['Ada', 'Ben', 'Cem', 'Dan', 'Eva'] as $name) {
            $this->id[$name] = $this->members->add("{$name} Example", [strtolower($name) . '@example.org'], 'S1', false, false);
        }
        $this->polls = new PollRepository($this->pdo);
        $this->poll = $this->addPoll(PollStatus::Open);
        [$this->ballots, $this->delegations] = $this->repositories($this->pdo);
    }

    /**
     * @return array{BallotRepository, DelegationRepository}
     */
    private function repositories(\PDO $pdo): array
    {
        $ballots = new BallotRepository($pdo, new BallotToken(self::SECRET));

        return [$ballots, new DelegationRepository($pdo, $ballots, new SettingsRepository($pdo))];
    }

    private function addPoll(PollStatus $status): int
    {
        return $this->polls->add(PollKind::Admission, 'Robin Example', null, 'S1', null, '', $status, '2026-01-01T00:00:00Z');
    }

    private function setting(string $key, string $value): void
    {
        $this->pdo->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$value, $key]);
    }

    private function turnout(): Turnout
    {
        return Turnout::of($this->members->all('en'), $this->ballots->participantIds($this->poll), $this->delegations->forPoll($this->poll));
    }

    private function ballotCount(): int
    {
        $statement = $this->pdo->query('SELECT count(*) FROM ballots');
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    public function testDelegatingHandsTheVoteOn(): void
    {
        self::assertNull($this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']));

        self::assertSame([$this->id['Ada'] => $this->id['Ben']], $this->delegations->forPoll($this->poll)->all());
        self::assertSame([$this->poll => $this->id['Ben']], $this->delegations->recipientsOf($this->id['Ada'], [$this->poll]));
        self::assertSame([], $this->delegations->recipientsOf($this->id['Ben'], [$this->poll]));
        // Not cast until Ben votes.
        self::assertSame(0, $this->turnout()->castWeight);
    }

    public function testDelegatingWithdrawsTheSendersOwnBallot(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ada'], Choice::Yes);

        $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']);

        self::assertNull($this->ballots->choice($this->poll, $this->id['Ada']));
        self::assertSame([], $this->ballots->participantIds($this->poll));
        self::assertSame(0, $this->ballotCount());
    }

    public function testDelegatingToAMemberWhoVotedAddsWeightToTheirBallot(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ben'], Choice::No);
        self::assertSame(1, $this->turnout()->castWeight);

        self::assertNull($this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']));

        self::assertSame(2, $this->turnout()->castWeight);
        self::assertSame(Choice::No, $this->ballots->choice($this->poll, $this->id['Ben']));
    }

    public function testVotingTakesTheVoteBackAfterTheRecipientVoted(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ben'], Choice::No);
        $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']);
        self::assertSame(2, $this->turnout()->castWeight);

        $cast = $this->ballots->cast($this->poll, $this->id['Ada'], Choice::Yes);

        self::assertSame(CastOutcome::Registered, $cast->outcome);
        self::assertSame($this->id['Ben'], $cast->reclaimedFrom);
        self::assertSame([], $this->delegations->forPoll($this->poll)->all());
        // Ben's ballot weighs one again, Ada's own ballot counts once.
        self::assertSame(2, $this->turnout()->castWeight);
        self::assertSame(2, $this->turnout()->voted);
        self::assertSame(Choice::Yes, $this->ballots->choice($this->poll, $this->id['Ada']));
    }

    public function testVotingWithoutADelegationReclaimsNothing(): void
    {
        self::assertNull($this->ballots->cast($this->poll, $this->id['Ada'], Choice::Yes)->reclaimedFrom);
    }

    public function testRevokingGivesTheVoteBackWithoutABallot(): void
    {
        $this->ballots->cast($this->poll, $this->id['Ben'], Choice::Yes);
        $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']);

        self::assertTrue($this->delegations->revoke($this->poll, $this->id['Ada']));
        self::assertFalse($this->delegations->revoke($this->poll, $this->id['Ada']));

        self::assertSame([], $this->delegations->forPoll($this->poll)->all());
        self::assertNull($this->ballots->choice($this->poll, $this->id['Ada']));
        self::assertSame(1, $this->turnout()->castWeight);
        self::assertSame(['Ada Example'], array_map(static fn($m): string => $m->name, array_slice($this->turnout()->missing, 0, 1)));
    }

    public function testEnforcesEveryRuleOnTheServer(): void
    {
        $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']);
        $this->delegations->delegate($this->poll, $this->id['Cem'], $this->id['Ben']);

        self::assertSame(DelegationRefusal::ToSelf, $this->delegations->delegate($this->poll, $this->id['Dan'], $this->id['Dan']));
        self::assertSame(DelegationRefusal::UnknownTarget, $this->delegations->delegate($this->poll, $this->id['Dan'], 999));
        self::assertSame(DelegationRefusal::TargetFull, $this->delegations->delegate($this->poll, $this->id['Dan'], $this->id['Ben']));
        self::assertSame(DelegationRefusal::TargetDelegated, $this->delegations->delegate($this->poll, $this->id['Dan'], $this->id['Ada']));
        self::assertSame(DelegationRefusal::SenderReceived, $this->delegations->delegate($this->poll, $this->id['Ben'], $this->id['Dan']));
        self::assertSame(DelegationRefusal::SenderDelegated, $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Dan']));
        self::assertSame([$this->id['Ada'] => $this->id['Ben'], $this->id['Cem'] => $this->id['Ben']], $this->delegations->forPoll($this->poll)->all());
    }

    public function testARefusedDelegationKeepsTheSendersBallot(): void
    {
        $this->ballots->cast($this->poll, $this->id['Dan'], Choice::Yes);

        self::assertSame(DelegationRefusal::ToSelf, $this->delegations->delegate($this->poll, $this->id['Dan'], $this->id['Dan']));

        self::assertSame(Choice::Yes, $this->ballots->choice($this->poll, $this->id['Dan']));
    }

    public function testTheLimitComesFromTheSettings(): void
    {
        $this->setting('max_received', '1');
        $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']);

        self::assertSame(DelegationRefusal::TargetFull, $this->delegations->delegate($this->poll, $this->id['Cem'], $this->id['Ben']));
    }

    public function testSwitchingDelegationOffKeepsExistingOnesAndBlocksNewOnes(): void
    {
        $this->delegations->delegate($this->poll, $this->id['Ada'], $this->id['Ben']);
        $this->setting('delegation_enabled', '0');

        self::assertSame(DelegationRefusal::Disabled, $this->delegations->delegate($this->poll, $this->id['Cem'], $this->id['Dan']));
        self::assertSame([$this->id['Ada'] => $this->id['Ben']], $this->delegations->forPoll($this->poll)->all());
        self::assertTrue($this->delegations->revoke($this->poll, $this->id['Ada']));
    }

    public function testRefusesDelegationsInAPollThatIsNotOpen(): void
    {
        foreach ([PollStatus::Draft, PollStatus::Closed] as $status) {
            $poll = $this->addPoll($status);
            foreach ([
                fn() => $this->delegations->delegate($poll, $this->id['Ada'], $this->id['Ben']),
                fn() => $this->delegations->revoke($poll, $this->id['Ada']),
            ] as $write) {
                try {
                    $write();
                    self::fail("Wrote a delegation in a {$status->value} poll.");
                } catch (PollNotOpenException) {
                    self::assertSame([], $this->delegations->forPoll($poll)->all());
                }
            }
        }
    }

    /**
     * Ada delegates to Ben while Ben delegates to Cem, each from a page that still offered the
     * other as free. Each write re-checks inside its transaction, so whichever commits second is
     * refused and no chain forms, in either order.
     */
    public function testTheSecondOfTwoCrossingDelegationsIsRefused(): void
    {
        [, $adaSide] = $this->repositories(ConnectionFactory::open($this->database));
        [, $benSide] = $this->repositories(ConnectionFactory::open($this->database));

        self::assertNull($adaSide->delegate($this->poll, $this->id['Ada'], $this->id['Ben']));
        self::assertSame(DelegationRefusal::SenderReceived, $benSide->delegate($this->poll, $this->id['Ben'], $this->id['Cem']));
        self::assertSame([$this->id['Ada'] => $this->id['Ben']], $this->delegations->forPoll($this->poll)->all());

        $other = $this->addPoll(PollStatus::Open);
        self::assertNull($benSide->delegate($other, $this->id['Ben'], $this->id['Cem']));
        self::assertSame(DelegationRefusal::TargetDelegated, $adaSide->delegate($other, $this->id['Ada'], $this->id['Ben']));
        self::assertSame([$this->id['Ben'] => $this->id['Cem']], $this->delegations->forPoll($other)->all());
    }

    /**
     * While one write holds the lock, another cannot start its check: BEGIN IMMEDIATE takes the
     * write lock before the rules are read.
     */
    public function testADelegationWaitsForTheWriteLock(): void
    {
        $other = ConnectionFactory::open($this->database);
        $other->setAttribute(\PDO::ATTR_TIMEOUT, 0);
        $other->exec('PRAGMA busy_timeout = 0');
        [, $blocked] = $this->repositories($other);

        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('locked');
        try {
            $blocked->delegate($this->poll, $this->id['Ben'], $this->id['Cem']);
        } finally {
            $this->pdo->exec('ROLLBACK');
        }
    }
}
