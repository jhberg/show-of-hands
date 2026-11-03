<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Tests\FixedClock;

final class LivePollTest extends HttpTestCase
{
    /** Tuesday, 3 November 2026, 21:05 in Berlin. */
    private const NOW = '2026-11-03T20:05:00Z';

    private int $kim;

    private int $lou;

    private int $max;

    private int $poll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at(self::NOW)]);
        $this->kim = $this->addMember('Kim Example', ['kim@example.org'], group: 'S1');
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->max = $this->addMember('Max Example', ['max@example.org'], board: true, group: null);
        $this->addMember('Ned Example', ['ned@example.org'], group: 'B1');
        $this->poll = $this->addPoll();
    }

    /**
     * @param array<string, string> $headers
     */
    private function live(string $query = '', array $headers = ['HX-Request' => 'true']): ResponseInterface
    {
        return $this->request('GET', "/polls/{$this->poll}/live{$query}", headers: $headers);
    }

    /** The <main> of the full poll page. */
    private function pageMain(string $query = ''): string
    {
        $html = $this->body($this->request('GET', "/polls/{$this->poll}{$query}"));
        $start = strpos($html, '<main ');
        $end = strpos($html, '</main>');
        self::assertIsInt($start);
        self::assertIsInt($end);

        return substr($html, $start, $end + strlen('</main>') - $start);
    }

    private function closeAt(string $time): void
    {
        $this->service(PollCloser::class)->close($this->poll, new \DateTimeImmutable($time));
    }

    /**
     * For every role and every panel, the refresh renders exactly the <main> of the full page,
     * so it never shows a member more than the page does.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function views(): iterable
    {
        yield 'member' => ['kim@example.org', '', true];
        yield 'member, names hidden' => ['kim@example.org', '', false];
        yield 'member, delegation panel' => ['kim@example.org', '?delegate=1', true];
        yield 'member, delegation panel, names hidden' => ['kim@example.org', '?delegate=1', false];
        yield 'recipient, names hidden' => ['lou@example.org', '', false];
        yield 'board' => ['max@example.org', '', false];
        yield 'board, confirmation' => ['max@example.org', '?confirm=close', true];
    }

    #[DataProvider('views')]
    public function testTheRefreshShowsWhatThePageShows(string $email, string $query, bool $showNames): void
    {
        $this->service(BallotRepository::class)->cast($this->poll, $this->max, Choice::Yes);
        $this->service(DelegationRepository::class)->delegate($this->poll, $this->kim, $this->lou);
        if (!$showNames) {
            $this->hideOpenNames();
        }
        $this->signIn($email);
        $page = $this->pageMain($query);

        $response = $this->live($query);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        // The page moves the focus to the confirmation once; the refresh leaves it where it is, and
        // marks <main> as still, so nothing in it rises into view again.
        $page = str_replace([' autofocus', '<main class="main"'], ['', '<main class="main still"'], $page);
        self::assertSame($page, trim($this->body($response)));
    }

    public function testTheRefreshKeepsTheOpenPanels(): void
    {
        $this->signIn('max@example.org');

        self::assertStringContainsString('hx-get="/polls/' . $this->poll . '/live"', $this->pageMain());
        self::assertStringContainsString('hx-get="/polls/' . $this->poll . '/live?confirm=close"', $this->pageMain('?confirm=close'));
        self::assertStringContainsString('hx-get="/polls/' . $this->poll . '/live?delegate=1"', $this->pageMain('?delegate=1'));
        self::assertStringContainsString('hx-get="/polls/' . $this->poll . '/live?delegate=1&amp;confirm=close"', $this->pageMain('?delegate=1&confirm=close'));
        self::assertStringContainsString('hx-trigger="every 15s, visibilitychange from:document"', $this->pageMain());
    }

    public function testTheBallotTurnsIntoTheResultAndBack(): void
    {
        $this->service(BallotRepository::class)->cast($this->poll, $this->kim, Choice::Yes);
        $this->signIn('kim@example.org');
        self::assertStringContainsString('id="choice-yes"', $this->body($this->live()));

        $this->closeAt('2026-11-03T19:00:00Z');
        $closed = $this->body($this->live());
        self::assertStringNotContainsString('id="choice-yes"', $closed);
        self::assertStringContainsString('<section class="result">', $closed);
        // An admin may still reopen the poll today, so the page keeps asking.
        self::assertStringContainsString('data-live', $closed);

        $pdo = $this->service(\PDO::class);
        $pdo->exec("UPDATE polls SET status = 'open', closed_at = NULL, reopened_at = '2026-11-03T20:00:00Z', reopen_count = 1 WHERE id = {$this->poll}");
        $pdo->exec("DELETE FROM poll_roll WHERE poll_id = {$this->poll}");
        $reopened = $this->body($this->live());
        self::assertStringContainsString('id="choice-yes" name="choice" value="yes" aria-pressed="true"', $reopened);
        self::assertStringNotContainsString('<section class="result">', $reopened);
    }

    public function testTheRefreshStopsOnceThePollCanNoLongerChange(): void
    {
        $this->closeAt('2026-11-02T19:00:00Z');
        $this->signIn('kim@example.org');

        $html = $this->body($this->live());

        self::assertStringContainsString('<section class="result">', $html);
        self::assertStringNotContainsString('data-live', $html);
        self::assertStringNotContainsString('data-live', $this->pageMain());
    }

    public function testTheRefreshLeavesTheToastForThePage(): void
    {
        $this->signIn('kim@example.org');
        $this->post("/polls/{$this->poll}/ballot", ['choice' => 'no']);

        $response = $this->live();

        self::assertSame('', $response->getHeaderLine('HX-Trigger'));
        self::assertStringContainsString('Stimme registriert: Nein.', $this->body($this->request('GET', "/polls/{$this->poll}")));
    }

    public function testABrowserThatOpensTheRefreshGetsThePage(): void
    {
        $this->signIn('kim@example.org');

        $response = $this->live('?delegate=1', headers: []);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame("/polls/{$this->poll}?delegate=1", $response->getHeaderLine('Location'));
    }

    public function testAPollThatIsGoneLoadsThePage(): void
    {
        $draft = $this->addPoll(status: PollStatus::Draft);
        $this->signIn('kim@example.org');

        $response = $this->request('GET', "/polls/{$draft}/live", headers: ['HX-Request' => 'true']);

        self::assertSame("/polls/{$draft}", $response->getHeaderLine('HX-Redirect'));
    }

    public function testASignedOutRefreshLoadsTheLoginPage(): void
    {
        $response = $this->live(headers: ['HX-Request' => 'true', 'HX-Current-URL' => "http://localhost:8080/polls/{$this->poll}"]);

        self::assertSame('/login?return=' . rawurlencode("/polls/{$this->poll}"), $response->getHeaderLine('HX-Redirect'));
    }
}
