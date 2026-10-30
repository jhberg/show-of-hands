<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Tests\FixedClock;

final class CloseTest extends HttpTestCase
{
    /** Tuesday, 3 November 2026, 21:05 in Berlin. */
    private const NOW = '2026-11-03T20:05:00Z';

    private int $kim;

    private int $lou;

    private int $max;

    private int $ned;

    private int $poll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at(self::NOW)]);
        $this->kim = $this->addMember('Kim Example', ['kim@example.org'], group: 'S1');
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->max = $this->addMember('Max Example', ['max@example.org'], board: true, group: null);
        $this->ned = $this->addMember('Ned Example', ['ned@example.org'], group: 'B1');
        $this->poll = $this->addPoll();
    }

    private function close(bool $htmx = false, ?int $poll = null): ResponseInterface
    {
        return $this->request(
            'POST',
            '/polls/' . ($poll ?? $this->poll) . '/close',
            [CsrfMiddleware::FIELD => $this->session->csrfToken()],
            headers: $htmx ? ['HX-Request' => 'true'] : [],
        );
    }

    /** The page, with the no-break space that percentages carry ("75,0 %") read as a plain one. */
    private function page(string $query = '', ?int $poll = null): string
    {
        return self::spaced($this->body($this->request('GET', '/polls/' . ($poll ?? $this->poll) . $query)));
    }

    private static function spaced(string $html): string
    {
        return str_replace("\u{a0}", ' ', $html);
    }

    private function cast(int $member, Choice $choice, ?int $poll = null): void
    {
        $this->service(BallotRepository::class)->cast($poll ?? $this->poll, $member, $choice);
    }

    public function testOnlyTheBoardSeesTheCloseOfAnOpenPoll(): void
    {
        $this->signIn('kim@example.org');
        self::assertStringNotContainsString('Abschluss durch den Vorstand', $this->page());

        $this->signIn('max@example.org');
        $html = $this->page();
        self::assertStringContainsString('<h2>Abschluss durch den Vorstand</h2>', $html);
        self::assertStringContainsString('Du bist als Vorstand hinterlegt und kannst diese Abstimmung abschließen.', $html);
        self::assertMatchesRegularExpression('#<form class="actions mt-12" method="get" action="/polls/' . $this->poll . '\#board"[^>]*>\s*<input type="hidden" name="confirm" value="close">\s*<button class="btn">Abstimmung abschließen</button>#', $html);
        self::assertStringNotContainsString('Wirklich abschließen?', $html);
    }

    public function testTheConfirmationSumsUpWhatIsStillMissing(): void
    {
        $this->cast($this->kim, Choice::Yes);
        $this->service(DelegationRepository::class)->delegate($this->poll, $this->ned, $this->lou);
        $this->signIn('max@example.org');

        $html = $this->page('?confirm=close');

        self::assertStringContainsString('<p><b>Wirklich abschließen?</b> 1 von 4 Stimmrechten sind abgegeben, 2 Mitglieder fehlen noch, 1 übertragene Stimme ist noch nicht abgegeben.</p>', $html);
        self::assertStringContainsString('<form class="row" method="post" action="/polls/' . $this->poll . '/close"', $html);
        self::assertStringContainsString('<button class="btn primary" autofocus>Abschließen und Ergebnis anzeigen</button>', $html);
        self::assertStringContainsString('<a class="btn" href="/polls/' . $this->poll . '#board" hx-get="/polls/' . $this->poll . '"', $html);
        self::assertStringNotContainsString('Abstimmung abschließen</button>', $html);

        $this->cast($this->lou, Choice::No);
        $this->cast($this->max, Choice::No);
        self::assertStringContainsString('<b>Wirklich abschließen?</b> 4 von 4 Stimmrechten sind abgegeben.</p>', $this->page('?confirm=close'));
    }

    public function testTheConfirmationOpensInPlaceWithHtmx(): void
    {
        $this->signIn('max@example.org');

        $html = $this->body($this->request('GET', "/polls/{$this->poll}?confirm=close", headers: ['HX-Request' => 'true']));

        self::assertStringStartsWith('<main class="main">', ltrim(preg_replace('/\{#.*?#\}/s', '', $html) ?? ''));
        self::assertStringContainsString('Wirklich abschließen?', $html);
    }

    public function testClosingShowsTheResultInPlaceOfTheBallot(): void
    {
        $this->cast($this->kim, Choice::Yes);
        $this->cast($this->lou, Choice::Yes);
        $this->cast($this->max, Choice::Yes);
        $this->cast($this->ned, Choice::No);
        $this->signIn('max@example.org');
        $this->request('GET', '/');

        $response = $this->close();
        self::assertSame(303, $response->getStatusCode());
        self::assertSame("/polls/{$this->poll}", $response->getHeaderLine('Location'));

        $html = $this->page();
        self::assertStringContainsString('Abstimmung abgeschlossen. Das Ergebnis ist jetzt sichtbar.', $html);
        self::assertStringContainsString('<span class="chip closed">Abgeschlossen</span>', $html);
        self::assertStringNotContainsString('class="ballot"', $html);
        self::assertStringNotContainsString('Abschluss durch den Vorstand', $html);
        self::assertStringNotContainsString('Zwischenstände', $html);
        self::assertMatchesRegularExpression('#<p class="verdict ja">\s*<span class="ok-ic"><svg.*?</svg></span>Aufgenommen<span class="pct num">75,0 % Ja</span>#s', $html);
        // Three of four is above two thirds: 3 Yes needed, the marker at 75 %, its label to the right.
        self::assertStringContainsString('<line x1="75%" x2="75%" y1="0" y2="100%"/>', $html);
        self::assertStringContainsString('<text x="75%" y="11" dx="6" text-anchor="start">⅔ = 3 Ja</text>', $html);
        self::assertStringContainsString('<rect class="j" height="100%" width="75%"/>', $html);
        self::assertStringContainsString('<rect class="n" x="75%" height="100%" width="25%"/>', $html);
        self::assertStringContainsString('<span class="j"><i></i>3 Ja · 75,0 %</span>', $html);
        self::assertStringContainsString('<span class="n"><i></i>1 Nein · 25,0 %</span>', $html);
        self::assertStringContainsString('<span class="r"><i></i>0 nicht abgegeben · 0,0 %</span>', $html);
        self::assertStringContainsString('3 von 4 Stimmberechtigten haben mit Ja gestimmt, das sind 75,0 %. Nötig sind mindestens 3 Ja-Stimmen, also zwei Drittel aller Stimmberechtigten. Nicht abgegebene Stimmen gelten als Enthaltung und zählen nicht als Ja. 4 von 4 Stimmrechten wurden abgegeben.', $html);
        self::assertStringContainsString('Diese Abstimmung wird samt Ergebnis am 17. November 2026 automatisch gelöscht.', $html);
        self::assertStringContainsString('<b>4</b> von 4 Stimmrechten abgegeben', $html);

        $poll = $this->service(PollRepository::class)->find($this->poll);
        self::assertNotNull($poll);
        self::assertSame(PollStatus::Closed, $poll->status);
        self::assertSame(self::NOW, $poll->closedAt);
        self::assertSame([4, 3, 1, 0], [$poll->tally?->eligible, $poll->tally?->yes, $poll->tally?->no, $poll->tally?->delegations]);
    }

    public function testClosingWithHtmxSwapsMainAndSendsTheToast(): void
    {
        $this->cast($this->kim, Choice::No);
        $this->signIn('max@example.org');

        $response = $this->close(htmx: true);
        $html = self::spaced($this->body($response));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"toast":"Abstimmung abgeschlossen. Das Ergebnis ist jetzt sichtbar."}', $response->getHeaderLine('HX-Trigger'));
        self::assertStringStartsWith('<main class="main">', ltrim(preg_replace('/\{#.*?#\}/s', '', $html) ?? ''));
        self::assertMatchesRegularExpression('#<p class="verdict nein">.*?Nicht aufgenommen<span class="pct num">0,0 % Ja</span>#s', $html);
        self::assertStringNotContainsString('Abschluss durch den Vorstand', $html);
    }

    public function testAMemberWithoutTheBoardRoleCannotClose(): void
    {
        $this->signIn('kim@example.org');

        self::assertSame(403, $this->close()->getStatusCode());
        self::assertSame(PollStatus::Open, $this->service(PollRepository::class)->find($this->poll)?->status);
    }

    public function testClosingAClosedPollKeepsTheFirstCount(): void
    {
        $this->cast($this->kim, Choice::Yes);
        $this->signIn('max@example.org');
        $this->close();

        self::assertSame('{"toast":"Die Abstimmung ist bereits abgeschlossen."}', $this->close(htmx: true)->getHeaderLine('HX-Trigger'));
        self::assertSame(1, $this->service(PollRepository::class)->find($this->poll)?->tally?->yes);
    }

    public function testADraftCannotBeClosed(): void
    {
        $draft = $this->addPoll(status: PollStatus::Draft);
        $this->signIn('max@example.org');

        self::assertSame(404, $this->close(poll: $draft)->getStatusCode());
        self::assertSame(PollStatus::Draft, $this->service(PollRepository::class)->find($draft)?->status);
    }

    public function testADelegatedVoteCountsForItsRecipientOnlyOnceTheyVoted(): void
    {
        // Ned hands his vote to Lou, who votes Yes. Max hands his to Kim, who does not vote.
        $delegations = $this->service(DelegationRepository::class);
        $delegations->delegate($this->poll, $this->ned, $this->lou);
        $delegations->delegate($this->poll, $this->max, $this->kim);
        $this->cast($this->lou, Choice::Yes);
        $this->signIn('max@example.org');

        $this->close();

        $tally = $this->service(PollRepository::class)->find($this->poll)?->tally;
        self::assertSame([2, 0, 1], [$tally?->yes, $tally?->no, $tally?->delegations]);
        $html = $this->page();
        self::assertStringContainsString('Nicht aufgenommen', $html);
        self::assertStringContainsString('<span class="r"><i></i>2 nicht abgegeben · 50,0 %</span>', $html);
        self::assertStringContainsString('2 von 4 Stimmrechten wurden abgegeben, 1 davon übertragen.', $html);
        self::assertStringContainsString('1 Mitglied hat abgestimmt, 2 haben die Stimme übertragen, 1 fehlt noch.', $html);
        self::assertMatchesRegularExpression('#<span>Max Example</span><svg.*?</svg><span>Kim Example</span><span class="chip">noch nicht abgegeben</span>#s', $html);
    }

    public function testTwoThirdsExactlyIsEnoughAndTheMarkerLabelFlipsNearTheEnd(): void
    {
        // With two members, two thirds rounds up to both of them: the marker sits at 100 %.
        $this->service(\PDO::class)->exec("DELETE FROM members WHERE id IN ({$this->lou}, {$this->ned})");
        $this->cast($this->kim, Choice::Yes);
        $this->cast($this->max, Choice::Yes);
        $this->signIn('max@example.org');

        $this->close();

        $html = $this->page();
        self::assertStringContainsString('Aufgenommen<span class="pct num">100,0 % Ja</span>', $html);
        self::assertStringContainsString('<text x="100%" y="11" dx="-6" text-anchor="end">⅔ = 2 Ja</text>', $html);
        self::assertStringContainsString('Nötig sind mindestens 2 Ja-Stimmen', $html);
    }

    public function testAnExceptionPollNamesItsVerdictInItsOwnWords(): void
    {
        $poll = $this->addPoll(kind: PollKind::Exception, question: 'May Robin sing at the concert?');
        foreach ([$this->kim, $this->lou, $this->max] as $member) {
            $this->cast($member, Choice::Yes, $poll);
        }
        $this->signIn('max@example.org');
        $this->close(poll: $poll);

        $html = $this->page(poll: $poll);
        self::assertStringContainsString('<p class="note"><b>May Robin sing at the concert?</b></p>', $html);
        self::assertStringContainsString('Ausnahme genehmigt<span class="pct num">75,0 % Ja</span>', $html);
    }

    public function testTheTurnoutOfAClosedPollStaysAsItWasAtTheClose(): void
    {
        $this->cast($this->kim, Choice::Yes);
        $this->signIn('max@example.org');
        $this->close();
        $members = $this->service(MemberRepository::class);
        $members->add('Uma Example', ['uma@example.org'], 'S1', false, false);
        $this->service(\PDO::class)->exec("UPDATE members SET name = 'Lou Renamed' WHERE id = {$this->lou}");

        $html = $this->page();

        self::assertStringContainsString('<b>1</b> von 4 Stimmrechten abgegeben', $html);
        self::assertStringContainsString('<span class="name">Lou Example</span>', $html);
        self::assertStringNotContainsString('Lou Renamed', $html);
        self::assertStringNotContainsString('Uma Example', $html);
        // Kim voted, so she is not among the missing members.
        self::assertStringNotContainsString('<span class="name">Kim Example</span>', $html);
    }

    public function testTheResultReadsTheEnglishWording(): void
    {
        $this->cast($this->kim, Choice::Yes);
        $this->service(\PDO::class)->exec("UPDATE members SET locale = 'en' WHERE id = {$this->max}");
        $this->signIn('max@example.org');
        $this->close();

        $html = $this->page();

        self::assertStringContainsString('Not admitted<span class="pct num">25.0% yes</span>', $html);
        self::assertStringContainsString('1 of 4 eligible voters voted yes, that is 25.0%. At least 3 yes votes are needed', $html);
        self::assertStringContainsString('This poll and its result will be deleted automatically on November 17, 2026.', $html);
    }

    public function testHomeListsClosedPollsByClosingTimeWithTheirVerdict(): void
    {
        $closer = $this->service(PollCloser::class);
        $accepted = $this->addPoll('Accepted Example', group: 'A1', date: '2026-10-20');
        foreach ([$this->kim, $this->lou, $this->max] as $member) {
            $this->cast($member, Choice::Yes, $accepted);
        }
        $closer->close($accepted, new \DateTimeImmutable('2026-10-20T20:00:00Z'));
        $declined = $this->addPoll('Declined Example', kind: PollKind::Exception, question: 'May they?', group: 'B1');
        $closer->close($declined, new \DateTimeImmutable('2026-10-28T20:00:00Z'));
        $this->signIn('kim@example.org');

        $html = strstr($this->body($this->request('GET', '/')), 'Abgeschlossen</h2>') ?: '';

        preg_match_all('#<a href="/polls/(\d+)">#', $html, $links);
        self::assertSame([$declined, $accepted], array_map(intval(...), $links[1]));
        self::assertMatchesRegularExpression('#Accepted Example</b>.*?</span>\s*</span><span class="chip ja"><svg.*?</svg>Aufgenommen</span>#s', $html);
        self::assertMatchesRegularExpression('#Declined Example</b>\s*<span class="meta">Ausnahme · Bass 1</span>\s*</span><span class="chip nein"><svg.*?</svg>Ausnahme abgelehnt</span>#s', $html);
        self::assertStringNotContainsString('Noch keine abgeschlossene Abstimmung.', $html);
    }
}
