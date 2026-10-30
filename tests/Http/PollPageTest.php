<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;

final class PollPageTest extends HttpTestCase
{
    private int $kim;

    private int $lou;

    private int $max;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kim = $this->addMember('Kim Example', ['kim@example.org'], group: 'S1');
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->max = $this->addMember('Max Example', ['max@example.org'], board: true, group: null);
    }

    private function vote(int $poll, string $choice, bool $htmx = false): ResponseInterface
    {
        return $this->request(
            'POST',
            "/polls/{$poll}/ballot",
            [CsrfMiddleware::FIELD => $this->session->csrfToken(), 'choice' => $choice],
            headers: $htmx ? ['HX-Request' => 'true'] : [],
        );
    }

    private function hideOpenNames(): void
    {
        $this->service(\PDO::class)->exec("UPDATE settings SET value = '0' WHERE key = 'show_open_names'");
    }

    public function testAnOpenAdmissionPollShowsHeadBallotAndTurnout(): void
    {
        $poll = $this->addPoll(date: '2026-11-03', note: 'Robin sings after the break.');
        $this->signIn('kim@example.org');

        $response = $this->request('GET', "/polls/{$poll}");
        $html = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Alle Abstimmungen', $html);
        self::assertStringContainsString('Abstimmung über die Aufnahme von', $html);
        self::assertStringContainsString('<h1>Robin Example</h1>', $html);
        self::assertStringContainsString('Sopran 1 · Vorsingen am Di., 3. November 2026', $html);
        self::assertStringContainsString('<i class="dot"></i>Offen', $html);
        self::assertStringContainsString('Robin sings after the break.', $html);
        self::assertStringContainsString('Soll Robin Example in den Chor aufgenommen werden?', $html);
        self::assertStringContainsString('<small>aufnehmen</small>', $html);
        self::assertStringContainsString('<small>nicht aufnehmen</small>', $html);
        self::assertSame(2, substr_count($html, 'aria-pressed="false"'));
        self::assertStringContainsString('Tippe auf Ja oder Nein.', $html);
        self::assertStringNotContainsString('Deine Stimme ist registriert', $html);
        self::assertStringContainsString('<b>0</b> von 3 Stimmrechten abgegeben', $html);
        self::assertStringContainsString('aria-valuenow="0"', $html);
        self::assertStringContainsString('0 Mitglieder haben abgestimmt, 3 fehlen noch.', $html);
        self::assertMatchesRegularExpression('#Sopran 1</div>\s*<div class="names"><span class="name">Kim Example</span>#', $html);
        self::assertMatchesRegularExpression('#Tenor 1</div>\s*<div class="names"><span class="name">Lou Example</span>#', $html);
        self::assertMatchesRegularExpression('#Ohne Gruppe</div>\s*<div class="names"><span class="name">Max Example</span>#', $html);
        self::assertStringContainsString('Zwischenstände gibt es nicht.', $html);
    }

    public function testAnExceptionPollAsksItsQuestionInTheBallotWhileOpen(): void
    {
        $poll = $this->addPoll(kind: PollKind::Exception, question: 'May Robin sing at the concert?');
        $this->signIn('kim@example.org');

        $html = $this->body($this->request('GET', "/polls/{$poll}"));

        self::assertStringContainsString('Abstimmung über eine Ausnahme in der Probezeit von', $html);
        self::assertStringContainsString('Sopran 1 · Datum offen', $html);
        self::assertStringContainsString('<p class="q">May Robin sing at the concert?</p>', $html);
        self::assertStringNotContainsString('<b>May Robin sing at the concert?</b>', $html);
        self::assertStringContainsString('<small>zustimmen</small>', $html);
        self::assertStringContainsString('<small>ablehnen</small>', $html);
    }

    public function testAClosedExceptionPollShowsItsQuestionInTheHeadAndNoBallot(): void
    {
        $poll = $this->addPoll(status: PollStatus::Closed, kind: PollKind::Exception, question: 'May Robin sing at the concert?', date: '2026-11-08');
        $this->signIn('kim@example.org');

        $html = $this->body($this->request('GET', "/polls/{$poll}"));

        self::assertStringContainsString('Sopran 1 · Entscheidung bis So., 8. November 2026', $html);
        self::assertStringContainsString('<p class="note"><b>May Robin sing at the concert?</b></p>', $html);
        self::assertStringContainsString('<span class="chip closed">Abgeschlossen</span>', $html);
        self::assertStringNotContainsString('class="ballot"', $html);
        self::assertStringNotContainsString('Zwischenstände', $html);
    }

    public function testADraftLooksLikeAPollThatDoesNotExist(): void
    {
        $draft = $this->addPoll(status: PollStatus::Draft);
        $this->signIn('kim@example.org');

        foreach (["/polls/{$draft}", '/polls/99'] as $path) {
            $response = $this->request('GET', $path);
            self::assertSame(404, $response->getStatusCode());
            self::assertStringContainsString('Diese Abstimmung gibt es nicht mehr oder sie ist noch nicht freigeschaltet.', $this->body($response));
        }
        self::assertSame(404, $this->vote($draft, 'yes')->getStatusCode());
        self::assertSame([], $this->service(BallotRepository::class)->participantIds($draft));
    }

    public function testCastingChangingAndKeepingTheVoteWithoutJavaScript(): void
    {
        $poll = $this->addPoll();
        $this->signIn('kim@example.org');
        $this->request('GET', '/');

        $response = $this->vote($poll, 'yes');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame("/polls/{$poll}", $response->getHeaderLine('Location'));
        // The session keeps the message key only. The page reads the choice from the ballot.
        self::assertSame(['key' => 'toast.ballot.registered', 'params' => []], $this->session->get('flash'));

        $html = $this->body($this->request('GET', "/polls/{$poll}"));
        self::assertStringContainsString('Stimme registriert: Ja.', $html);
        self::assertMatchesRegularExpression('#id="choice-yes" name="choice" value="yes" aria-pressed="true"><span class="big">Ja</span><small>aufnehmen</small><span class="sel">.*Deine Stimme</span>#s', $html);
        self::assertStringContainsString('id="choice-no" name="choice" value="no" aria-pressed="false"', $html);
        self::assertStringContainsString('<b>Deine Stimme ist registriert: Ja.</b> Du kannst sie ändern', $html);
        self::assertStringNotContainsString('Tippe auf Ja oder Nein.', $html);
        self::assertStringContainsString('<b>1</b> von 3 Stimmrechten abgegeben', $html);
        self::assertStringContainsString('1 Mitglied hat abgestimmt, 2 fehlen noch.', $html);
        self::assertStringNotContainsString('<span class="name">Kim Example</span>', $html);

        $this->vote($poll, 'no');
        self::assertStringContainsString('Stimme geändert: Nein.', $this->body($this->request('GET', "/polls/{$poll}")));

        $this->vote($poll, 'no');
        $html = $this->body($this->request('GET', "/polls/{$poll}"));
        self::assertStringContainsString('Bleibt bei Nein.', $html);
        self::assertStringContainsString('<b>1</b> von 3 Stimmrechten abgegeben', $html);
        self::assertSame(Choice::No, $this->service(BallotRepository::class)->choice($poll, $this->kim));
    }

    public function testHtmxGetsMainAndTheToastInAHeader(): void
    {
        $poll = $this->addPoll();
        $this->signIn('kim@example.org');
        $this->request('GET', '/');

        $response = $this->vote($poll, 'yes', htmx: true);
        $html = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"toast":"Stimme registriert: Ja."}', $response->getHeaderLine('HX-Trigger'));
        self::assertStringStartsWith('<main class="main">', ltrim(preg_replace('/\{#.*?#\}/s', '', $html) ?? ''));
        self::assertStringNotContainsString('<header', $html);
        self::assertStringNotContainsString('id="toast"', $html);
        self::assertStringContainsString('aria-pressed="true"', $html);
        self::assertStringContainsString('<b>1</b> von 3 Stimmrechten abgegeben', $html);
        self::assertNull($this->session->get('flash'));

        self::assertSame('{"toast":"Bleibt bei Ja."}', $this->vote($poll, 'yes', htmx: true)->getHeaderLine('HX-Trigger'));
    }

    public function testABallotForAClosedPollIsRefused(): void
    {
        $poll = $this->addPoll(status: PollStatus::Closed);
        $this->signIn('kim@example.org');
        $this->request('GET', '/');

        self::assertSame(303, $this->vote($poll, 'yes')->getStatusCode());
        $html = $this->body($this->request('GET', "/polls/{$poll}"));
        self::assertStringContainsString('Die Abstimmung ist bereits abgeschlossen.', $html);
        self::assertSame([], $this->service(BallotRepository::class)->participantIds($poll));

        self::assertSame('{"toast":"Die Abstimmung ist bereits abgeschlossen."}', $this->vote($poll, 'no', htmx: true)->getHeaderLine('HX-Trigger'));
    }

    public function testTheToastNamesTheChoiceWhenThePollClosedRightAfterTheCast(): void
    {
        $poll = $this->addPoll();
        $this->signIn('kim@example.org');
        $this->request('GET', '/');
        $this->vote($poll, 'yes');
        $this->service(\PDO::class)->exec("UPDATE polls SET status = 'closed' WHERE id = {$poll}");

        self::assertStringContainsString('Stimme registriert: Ja.', $this->body($this->request('GET', "/polls/{$poll}")));
    }

    public function testAnUnknownChoiceChangesNothing(): void
    {
        $poll = $this->addPoll();
        $this->signIn('kim@example.org');
        $this->request('GET', '/');

        $response = $this->vote($poll, 'maybe');

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->session->get('flash'));
        self::assertSame([], $this->service(BallotRepository::class)->participantIds($poll));
    }

    public function testMembersSeeOnlyTheCountWhenNamesAreHidden(): void
    {
        $poll = $this->addPoll();
        $this->hideOpenNames();
        $this->signIn('lou@example.org');

        $html = $this->body($this->request('GET', "/polls/{$poll}"));
        self::assertStringContainsString('Noch nicht abgestimmt <span class="n num">3</span>', $html);
        self::assertStringContainsString('Die Namen sehen nur Vorstand und Verwaltung.', $html);
        self::assertStringNotContainsString('<span class="name">', $html);

        $this->signIn('max@example.org');
        $html = $this->body($this->request('GET', "/polls/{$poll}"));
        self::assertStringNotContainsString('Die Namen sehen nur Vorstand und Verwaltung.', $html);
        self::assertStringContainsString('<span class="name">Kim Example</span>', $html);
    }

    public function testEveryoneHasVoted(): void
    {
        $poll = $this->addPoll();
        $ballots = $this->service(BallotRepository::class);
        foreach ([$this->kim, $this->lou, $this->max] as $member) {
            $ballots->cast($poll, $member, Choice::Yes);
        }
        $this->signIn('kim@example.org');

        $html = $this->body($this->request('GET', "/polls/{$poll}"));

        self::assertStringContainsString('3 Mitglieder haben abgestimmt. Alle haben mitgemacht.', $html);
        self::assertStringContainsString('<p class="all-done">Alle Mitglieder haben abgestimmt.</p>', $html);
        self::assertStringNotContainsString('Noch nicht abgestimmt', $html);
        self::assertStringContainsString('width="100%"', $html);
    }

    public function testThePageSpeaksEnglish(): void
    {
        $poll = $this->addPoll(date: '2026-11-03');
        $this->service(MemberRepository::class)->setLocale($this->kim, 'en');
        $this->signIn('kim@example.org');
        $this->request('GET', '/');
        $this->vote($poll, 'no');

        $html = $this->body($this->request('GET', "/polls/{$poll}"));

        self::assertStringContainsString('Vote registered: No.', $html);
        self::assertStringContainsString('Audition on Tue, November 3, 2026', $html);
        self::assertStringContainsString('Should Robin Example be admitted to the choir?', $html);
        self::assertStringContainsString('<b>Your vote is registered: No.</b>', $html);
        self::assertStringContainsString('<b>1</b> of 3 voting rights cast', $html);
        self::assertStringContainsString('1 member has voted, 2 are still missing.', $html);
        self::assertStringContainsString('No group', $html);
    }
}
