<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Paths;

/**
 * While a poll is open, nothing reads the ballots table except the signed-in member's own row.
 */
final class BallotSecrecyTest extends HttpTestCase
{
    private const OWN_BALLOT_READ = 'SELECT choice FROM ballots WHERE poll_id = ? AND token = ?';

    protected function setUp(): void
    {
        parent::setUp();
        $database = (new Paths(dirname(__DIR__, 2), $this->dataDir))->database();
        $this->app = $this->createApp(overrides: [\PDO::class => static fn(): \PDO => ConnectionFactory::open($database, LoggingPdo::class)]);
        LoggingPdo::reset();
    }

    public function testTheOpenPollPathReadsOnlyTheMembersOwnBallot(): void
    {
        $kim = $this->addMember('Kim Example', ['kim@example.org']);
        $lou = $this->addMember('Lou Example', ['lou@example.org']);
        $poll = $this->addPoll();
        $this->service(BallotRepository::class)->cast($poll, $lou, Choice::No);
        $this->signIn('kim@example.org');
        $ownToken = $this->service(BallotToken::class)->for($poll, $kim);

        LoggingPdo::reset();
        $this->request('GET', '/');
        $this->request('GET', "/polls/{$poll}");
        $this->request('POST', "/polls/{$poll}/ballot", [CsrfMiddleware::FIELD => $this->session->csrfToken(), 'choice' => 'yes']);
        $this->request('GET', "/polls/{$poll}");
        $this->request('POST', "/polls/{$poll}/ballot", [CsrfMiddleware::FIELD => $this->session->csrfToken(), 'choice' => 'no'], headers: ['HX-Request' => 'true']);
        $this->request('GET', '/');

        $touching = array_values(array_filter(LoggingPdo::entries(), static fn(array $entry): bool => stripos($entry['sql'], 'ballots') !== false));
        $reads = array_values(array_filter($touching, static fn(array $entry): bool => !str_starts_with(ltrim($entry['sql']), 'INSERT')));
        self::assertNotEmpty($reads);
        foreach ($reads as $entry) {
            self::assertSame(self::OWN_BALLOT_READ, $entry['sql']);
            self::assertSame([$poll, $ownToken], $entry['params']);
        }
        // The only writes are the member's own ballot, keyed by their token: one cast, one change.
        $writes = array_values(array_filter($touching, static fn(array $entry): bool => str_starts_with(ltrim($entry['sql']), 'INSERT')));
        self::assertCount(2, $writes);
        foreach ($writes as $entry) {
            self::assertSame([$poll, $ownToken], array_slice($entry['params'], 0, 2));
        }
    }
}
