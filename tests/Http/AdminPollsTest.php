<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\Majority;
use ShowOfHands\Domain\MajorityBase;
use ShowOfHands\Domain\MajorityShare;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\Settings;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Paths;
use ShowOfHands\Tests\FixedClock;

final class AdminPollsTest extends HttpTestCase
{
    /** Tuesday, 3 November 2026, 21:05 in Berlin. */
    private const NOW = '2026-11-03T20:05:00Z';

    private int $ada;

    private int $kim;

    private int $lou;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useClock(self::NOW);
        $this->ada = $this->addMember('Ada Example', ['ada@example.org'], admin: true, board: true, group: 'S1');
        $this->kim = $this->addMember('Kim Example', ['kim@example.org'], group: 'A1');
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], board: true, group: 'T1');
        $this->signIn('ada@example.org');
    }

    /** A fresh app on the same data, the session kept, at another moment. */
    private function useClock(string $now, bool $logging = false): void
    {
        $overrides = [Clock::class => FixedClock::at($now)];
        if ($logging) {
            $database = (new Paths(dirname(__DIR__, 2), $this->dataDir))->database();
            $overrides[\PDO::class] = static fn(): \PDO => ConnectionFactory::open($database, LoggingPdo::class);
        }
        $this->app = $this->createApp(overrides: $overrides);
    }

    private function get(string $uri, bool $htmx = false): string
    {
        return str_replace("\u{a0}", ' ', $this->body($this->request('GET', $uri, headers: $htmx ? ['HX-Request' => 'true'] : [])));
    }

    /**
     * @param array<string, string> $fields
     */
    private function save(array $fields, ?int $poll = null, bool $htmx = false): ResponseInterface
    {
        return $this->post('/admin/polls' . ($poll === null ? '' : "/{$poll}"), $fields, $htmx);
    }

    private function closeAt(int $poll, string $at): void
    {
        $this->service(PollCloser::class)->close($poll, new \DateTimeImmutable($at));
    }

    private function cell(int $poll, string $column): mixed
    {
        $statement = $this->service(\PDO::class)->prepare("SELECT {$column} FROM polls WHERE id = ?");
        $statement->execute([$poll]);

        return $statement->fetchColumn();
    }

    private function rows(string $table, int $poll): int
    {
        $statement = $this->service(\PDO::class)->prepare("SELECT count(*) FROM {$table} WHERE poll_id = ?");
        $statement->execute([$poll]);

        return (int) $statement->fetchColumn();
    }

    public function testTheListSortsOpenThenDraftsThenClosedEachByDateWithTurnoutAndChips(): void
    {
        $draft = $this->addPoll('Draft Example', PollStatus::Draft, date: '2026-12-01');
        $openLate = $this->addPoll('Late Example', date: '2026-11-20');
        $openUndated = $this->addPoll('Undated Example', PollStatus::Open, PollKind::Exception, 'May they?', 'T1');
        $openEarly = $this->addPoll('Early Example', date: '2026-11-10');
        $closed = $this->addPoll('Closed Example', date: '2026-11-01');
        $this->service(BallotRepository::class)->cast($openEarly, $this->kim, Choice::Yes);
        $this->service(DelegationRepository::class)->delegate($openEarly, $this->lou, $this->kim);
        foreach ([$this->ada, $this->kim] as $member) {
            $this->service(BallotRepository::class)->cast($closed, $member, Choice::Yes);
        }
        $this->closeAt($closed, '2026-11-02T19:00:00Z');

        $html = $this->get('/admin/polls');

        preg_match_all('#<a class="btn sm" href="/admin/polls/(\d+)">#', $html, $links);
        self::assertSame([$openLate, $openEarly, $openUndated, $draft, $closed], array_map(intval(...), $links[1]));
        self::assertStringContainsString('<a class="btn primary" href="/admin/polls/new">Neue Abstimmung</a>', $html);
        self::assertMatchesRegularExpression('#Early Example</b>\s*<span class="meta">Aufnahme · Sopran 1 · Di., 10. November 2026</span>.*?<b>2</b> / 3</span><span class="chip open"><i class="dot"></i>Offen</span>#s', $html);
        self::assertMatchesRegularExpression('#Undated Example</b>\s*<span class="meta">Ausnahme in der Probezeit · Tenor 1 · Datum offen</span>#s', $html);
        self::assertMatchesRegularExpression('#Draft Example</b>.*?<div class="side"><span class="chip">Entwurf</span>#s', $html);
        self::assertMatchesRegularExpression('#Closed Example</b>\s*<span class="meta">Aufnahme · Sopran 1 · So., 1. November 2026 · Löschung am 16. November 2026</span>.*?<b>2</b> / 3</span><span class="chip ja">#s', $html);
    }

    public function testTheListSaysWhenThereAreNoPolls(): void
    {
        self::assertStringContainsString('Noch keine Abstimmungen.', $this->get('/admin/polls'));
    }

    public function testANewPollIsSavedAsADraftWithTheMajorityInForce(): void
    {
        $this->service(SettingsRepository::class)->save(new Settings(true, 2, true, 14, new Majority(MajorityShare::Half, MajorityBase::Cast)));
        $form = $this->get('/admin/polls/new');
        self::assertStringContainsString('<h1>Neue Abstimmung</h1>', $form);
        self::assertStringContainsString('<input type="radio" name="kind" value="admission" checked>', $form);
        // The page renders the question field for every kind; CSS hides it while admission is checked.
        self::assertStringContainsString('<div class="field" data-kind-only="exception">', $form);
        self::assertStringContainsString('data-admission="Name der Person, die sich bewirbt" data-exception="Name der Person in der Probezeit">Name der Person, die sich bewirbt</label>', $form);
        self::assertStringContainsString('Als Entwurf speichern', $form);

        $response = $this->save(['kind' => 'admission', 'subject_name' => '  Robin Example ', 'question' => 'ignored', 'group' => 'A1', 'date' => '2026-11-10', 'note' => ' See you. ']);

        $poll = $this->service(PollRepository::class)->all()[0];
        self::assertSame(303, $response->getStatusCode());
        self::assertSame("/admin/polls/{$poll->id}", $response->getHeaderLine('Location'));
        self::assertSame(PollStatus::Draft, $poll->status);
        self::assertSame(['Robin Example', null, 'A1', '2026-11-10', 'See you.'], [$poll->subjectName, $poll->question, $poll->voiceGroupKey, $poll->date, $poll->note]);
        self::assertEquals(new Majority(MajorityShare::Half, MajorityBase::Cast), $poll->majority);
        self::assertStringContainsString('Entwurf gespeichert. Jetzt freischalten, wenn es losgehen soll.', $this->get("/admin/polls/{$poll->id}"));
    }

    public function testARefusedSaveKeepsTheValuesAndNamesTheField(): void
    {
        $response = $this->save(['kind' => 'exception', 'subject_name' => 'Robin Example', 'question' => ' ', 'group' => 'T1', 'date' => '', 'note' => 'A note']);

        self::assertSame(422, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('<input type="radio" name="kind" value="exception" checked>', $html);
        self::assertStringContainsString('<div class="field" data-kind-only="exception">', $html);
        self::assertStringContainsString('>Name der Person in der Probezeit</label>', $html);
        self::assertStringContainsString('>Entscheidung bis</label>', $html);
        self::assertMatchesRegularExpression('#<textarea id="pf-question" name="question" rows="2" placeholder="[^"]*" autofocus aria-invalid="true" aria-describedby="pf-question-error">#', $html);
        self::assertStringContainsString('<p class="field-error" id="pf-question-error">Bitte die Frage formulieren, über die abgestimmt wird.</p>', $html);
        self::assertStringContainsString('value="Robin Example"', $html);
        self::assertStringContainsString('<option value="T1" selected>', $html);
        self::assertStringContainsString('>A note</textarea>', $html);
        self::assertSame([], $this->service(PollRepository::class)->all());

        $htmx = $this->save(['kind' => 'admission', 'subject_name' => '', 'group' => 'S1', 'date' => '', 'note' => ''], htmx: true);
        self::assertSame(422, $htmx->getStatusCode());
        self::assertSame(['toast' => 'Bitte den Namen eintragen.'], json_decode($htmx->getHeaderLine('HX-Trigger'), true));
        self::assertStringStartsWith('<form class="panel form poll-form" id="poll-form"', ltrim(preg_replace('/\{#.*?#\}/s', '', $this->body($htmx)) ?? ''));
    }

    public function testForgedValuesGetBadRequest(): void
    {
        $valid = ['kind' => 'admission', 'subject_name' => 'Robin Example', 'group' => 'S1', 'date' => '', 'note' => ''];
        foreach ([['kind' => 'other'], ['group' => 'ZZ'], ['date' => '2026-02-30'], ['date' => 'tomorrow']] as $forged) {
            self::assertSame(400, $this->save($forged + $valid)->getStatusCode(), json_encode($forged, JSON_THROW_ON_ERROR));
        }
        self::assertSame([], $this->service(PollRepository::class)->all());
    }

    public function testADraftIsEditableInFull(): void
    {
        $poll = $this->addPoll('Robin Example', PollStatus::Draft);
        self::assertStringContainsString('<h1>Abstimmung bearbeiten</h1>', $this->get("/admin/polls/{$poll}/edit"));

        $response = $this->save(['kind' => 'exception', 'subject_name' => 'Sam Example', 'question' => 'May Sam sing?', 'group' => 'B1', 'date' => '2026-12-24', 'note' => 'Note'], $poll);

        self::assertSame("/admin/polls/{$poll}", $response->getHeaderLine('Location'));
        $saved = $this->service(PollRepository::class)->find($poll);
        self::assertNotNull($saved);
        self::assertSame([PollKind::Exception, 'Sam Example', 'May Sam sing?', 'B1', '2026-12-24', 'Note'], [$saved->kind, $saved->subjectName, $saved->question, $saved->voiceGroupKey, $saved->date, $saved->note]);
        self::assertStringContainsString('Gespeichert.', $this->get("/admin/polls/{$poll}"));
    }

    public function testAnOpenPollEditsOnlyItsNoteAndDate(): void
    {
        $poll = $this->addPoll('Robin Example', note: 'Old');
        $form = $this->get("/admin/polls/{$poll}/edit");
        self::assertStringNotContainsString('name="kind"', $form);
        self::assertStringNotContainsString('name="subject_name"', $form);
        self::assertStringContainsString('Art, Name, Frage und Stimmgruppe stehen fest, seit abgestimmt werden kann.', $form);
        self::assertStringContainsString('Änderungen sind sofort für alle sichtbar.', $form);

        $this->save(['date' => '2026-11-10', 'note' => 'New'], $poll);

        $saved = $this->service(PollRepository::class)->find($poll);
        self::assertNotNull($saved);
        self::assertSame(['Robin Example', 'S1', '2026-11-10', 'New'], [$saved->subjectName, $saved->voiceGroupKey, $saved->date, $saved->note]);
        self::assertStringContainsString('Gespeichert.', $this->get("/admin/polls/{$poll}"));
    }

    public function testADraftFormSavedAfterThePollWasPublishedKeepsTheFixedValues(): void
    {
        $poll = $this->addPoll('Robin Example', PollStatus::Draft);
        $this->service(PollRepository::class)->publish($poll, new \DateTimeImmutable(self::NOW));

        $this->save(['kind' => 'exception', 'subject_name' => 'Sam Example', 'question' => 'May Sam sing?', 'group' => 'B1', 'date' => '', 'note' => 'New'], $poll);

        $saved = $this->service(PollRepository::class)->find($poll);
        self::assertNotNull($saved);
        self::assertSame([PollKind::Admission, 'Robin Example', 'New'], [$saved->kind, $saved->subjectName, $saved->note]);
        self::assertStringContainsString('Die Abstimmung wurde inzwischen geändert. Die Seite zeigt jetzt den aktuellen Stand.', $this->get("/admin/polls/{$poll}"));
    }

    public function testAClosedPollIsNotEditable(): void
    {
        $poll = $this->addPoll('Robin Example', note: 'Old');
        $this->closeAt($poll, self::NOW);

        self::assertSame("/admin/polls/{$poll}", $this->request('GET', "/admin/polls/{$poll}/edit")->getHeaderLine('Location'));
        $this->save(['date' => '2026-11-10', 'note' => 'New'], $poll);

        self::assertSame('Old', $this->cell($poll, 'note'));
        self::assertStringContainsString('Die Abstimmung wurde inzwischen geändert.', $this->get("/admin/polls/{$poll}"));
    }

    public function testTheDraftDetailPublishesOnce(): void
    {
        $poll = $this->addPoll('Robin Example', PollStatus::Draft, date: '2026-11-10');
        $html = $this->get("/admin/polls/{$poll}");
        self::assertStringContainsString('<p class="kicker">Aufnahme · Sopran 1 · Vorsingen am Di., 10. November 2026</p>', $html);
        self::assertStringContainsString('<h2 id="poll-status-title" tabindex="-1">Entwurf</h2>', $html);
        self::assertStringContainsString('<code id="share-link">http://localhost:8080/polls/' . $poll . '</code>', $html);
        self::assertStringNotContainsString('Beteiligung', $html);

        $response = $this->post("/admin/polls/{$poll}/publish", htmx: true);

        self::assertSame(['toast' => 'Freigeschaltet. Mitglieder können jetzt abstimmen.'], json_decode($response->getHeaderLine('HX-Trigger'), true));
        self::assertSame("/admin/polls/{$poll}", $response->getHeaderLine('HX-Replace-Url'));
        self::assertStringContainsString('<h2 id="poll-status-title" tabindex="-1">Offen seit 3. November 2026 um 21:05 Uhr</h2>', $this->body($response));
        self::assertSame('open', $this->cell($poll, 'status'));
        self::assertSame('2026-11-03T20:05:00Z', $this->cell($poll, 'opened_at'));

        $again = $this->post("/admin/polls/{$poll}/publish");
        self::assertSame("/admin/polls/{$poll}", $again->getHeaderLine('Location'));
        self::assertStringContainsString('Die Abstimmung wurde inzwischen geändert.', $this->get("/admin/polls/{$poll}"));
    }

    public function testOnlyADraftCanBeDeletedAfterTheConfirmation(): void
    {
        $draft = $this->addPoll('Robin Example', PollStatus::Draft);
        $html = $this->get("/admin/polls/{$draft}?confirm=delete");
        self::assertStringContainsString('<p><b>Entwurf wirklich löschen?</b> Das lässt sich nicht rückgängig machen.</p>', $html);
        self::assertStringContainsString('<button class="btn danger" autofocus>Ja, löschen</button>', $html);

        $response = $this->post("/admin/polls/{$draft}/delete");

        self::assertSame('/admin/polls', $response->getHeaderLine('Location'));
        self::assertNull($this->service(PollRepository::class)->find($draft));
        self::assertStringContainsString('Entwurf gelöscht.', $this->get('/admin/polls'));

        $open = $this->addPoll('Sam Example');
        self::assertStringNotContainsString('Entwurf wirklich löschen?', $this->get("/admin/polls/{$open}?confirm=delete"));
        $this->post("/admin/polls/{$open}/delete");
        self::assertNotNull($this->service(PollRepository::class)->find($open));
    }

    public function testTheOpenDetailNamesTheBoardShowsEveryNameAndNoResult(): void
    {
        $this->service(SettingsRepository::class)->save(new Settings(true, 2, false, 14, Majority::default()));
        $poll = $this->addPoll('Robin Example', PollStatus::Open, PollKind::Exception, 'May Robin sing?', note: 'A note');
        $this->service(\PDO::class)->exec("UPDATE polls SET opened_at = '2026-11-01T18:30:00Z' WHERE id = {$poll}");
        $this->service(BallotRepository::class)->cast($poll, $this->kim, Choice::Yes);

        $html = $this->get("/admin/polls/{$poll}");

        self::assertStringContainsString('<p class="note"><b lang="de">May Robin sing?</b></p>', $html);
        self::assertStringContainsString('<p class="note" lang="de">A note</p>', $html);
        self::assertStringContainsString('<h2 id="poll-status-title" tabindex="-1">Offen seit 1. November 2026 um 19:30 Uhr</h2>', $html);
        self::assertStringContainsString('vom Vorstand direkt auf der Abstimmungsseite (Ada, Lou). Erst danach ist das Ergebnis sichtbar.</p>', $html);
        self::assertStringContainsString('<a class="btn ghost" href="/admin/polls/' . $poll . '/edit">Hinweis bearbeiten</a>', $html);
        self::assertStringContainsString('<b>Ergebnis:</b> wird erst nach dem Abschluss berechnet.', $html);
        self::assertStringContainsString('<b>1</b> von 3 Stimmrechten abgegeben', $html);
        // Admins see the names even with the setting off.
        self::assertStringContainsString('<span class="name">Lou Example</span>', $html);
        self::assertStringNotContainsString('Ja-Stimme', $html);
        self::assertStringNotContainsString('wieder geöffnet', $html);
    }

    public function testTheClosedDetailShowsTheResultAndOffersTheReopenOnlyOnTheCloseDay(): void
    {
        $poll = $this->addPoll('Robin Example');
        $this->service(BallotRepository::class)->cast($poll, $this->kim, Choice::Yes);
        $this->closeAt($poll, '2026-11-03T19:00:00Z');

        $html = $this->get("/admin/polls/{$poll}");
        self::assertStringContainsString('<h2 id="poll-status-title" tabindex="-1">Abgeschlossen am 3. November 2026 um 20:00 Uhr</h2>', $html);
        self::assertStringContainsString('Die Zahl der Stimmberechtigten (3) wurde beim Abschluss festgehalten. Die Abstimmung wird am 17. November 2026 samt Ergebnis automatisch gelöscht.', $html);
        self::assertStringContainsString('href="/admin/polls/' . $poll . '?confirm=reopen#poll-status"', $html);
        self::assertStringContainsString('Ein versehentlicher Abschluss lässt sich nur heute, am Tag des Abschlusses, zurücknehmen.', $html);
        self::assertStringContainsString('<h2 id="result-title" tabindex="-1">Ergebnis</h2>', $html);
        self::assertStringContainsString('<span class="name">Ada Example</span>', $html);
        self::assertStringContainsString('<p><b>Wirklich wieder öffnen?</b> Das Ergebnis war bereits für alle sichtbar.', $this->get("/admin/polls/{$poll}?confirm=reopen"));

        // 00:05 the next day in Berlin.
        $this->useClock('2026-11-03T23:05:00Z');
        $html = $this->get("/admin/polls/{$poll}?confirm=reopen");
        self::assertStringContainsString('<button class="btn" disabled>Wieder öffnen</button>', $html);
        self::assertStringContainsString('Wieder öffnen war nur am Tag des Abschlusses möglich. Seitdem ist das Ergebnis endgültig.', $html);
        self::assertStringNotContainsString('Wirklich wieder öffnen?', $html);
    }

    public function testReopeningClearsTheTallyAndTheSnapshotAndKeepsTheBallots(): void
    {
        $poll = $this->addPoll('Robin Example');
        $this->service(BallotRepository::class)->cast($poll, $this->kim, Choice::Yes);
        $this->closeAt($poll, '2026-11-03T19:00:00Z');

        $response = $this->post("/admin/polls/{$poll}/reopen");

        self::assertSame("/admin/polls/{$poll}", $response->getHeaderLine('Location'));
        self::assertSame(['open', null, null, 1, '2026-11-03T20:05:00Z'], [
            $this->cell($poll, 'status'), $this->cell($poll, 'closed_at'), $this->cell($poll, 'eligible'), $this->cell($poll, 'reopen_count'), $this->cell($poll, 'reopened_at'),
        ]);
        self::assertSame(0, $this->rows('poll_roll', $poll));
        self::assertSame(1, $this->rows('ballots', $poll));
        $detail = $this->get("/admin/polls/{$poll}");
        self::assertStringContainsString('Abstimmung wieder geöffnet. Alle Mitglieder sehen den Hinweis.', $detail);
        self::assertStringContainsString('Diese Abstimmung wurde am 3. November 2026 um 21:05 Uhr wieder geöffnet.', $detail);

        $this->signIn('kim@example.org');
        $page = $this->get("/polls/{$poll}");
        self::assertStringContainsString('<span class="chip">wieder geöffnet</span>', $page);
        self::assertStringContainsString('<b>Wieder geöffnet am 3. November 2026 um 21:05 Uhr.</b> Die Verwaltung hat den Abschluss zurückgenommen.', $page);
        self::assertStringContainsString('<span class="sel">', $page, 'The member still sees their own vote.');

        $this->closeAt($poll, '2026-11-03T20:10:00Z');
        $page = $this->get("/polls/{$poll}");
        self::assertStringContainsString('1 von 3 Stimmrechten wurden abgegeben. Die Abstimmung wurde zwischendurch einmal wieder geöffnet.', $page);
        self::assertStringNotContainsString('wieder geöffnet</span>', $page);
    }

    public function testReopeningIsRefusedAfterTheCloseDayAndForAPollThatIsNotClosed(): void
    {
        $poll = $this->addPoll('Robin Example');
        $this->closeAt($poll, '2026-11-03T19:00:00Z');
        $this->useClock('2026-11-03T23:05:00Z');

        $response = $this->post("/admin/polls/{$poll}/reopen", htmx: true);

        self::assertSame(['toast' => 'Wieder öffnen ist nur am Tag des Abschlusses möglich.'], json_decode($response->getHeaderLine('HX-Trigger'), true));
        self::assertSame('closed', $this->cell($poll, 'status'));

        $open = $this->addPoll('Sam Example');
        $this->post("/admin/polls/{$open}/reopen");
        self::assertSame(0, $this->cell($open, 'reopen_count'));
    }

    public function testAMissingPollNamesTheRetention(): void
    {
        $response = $this->request('GET', '/admin/polls/99');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Abstimmung nicht gefunden. Abgeschlossene Abstimmungen werden nach 14 Tagen automatisch gelöscht.', $this->body($response));
        self::assertSame(404, $this->post('/admin/polls/99/publish')->getStatusCode());
    }

    public function testMembersAndBoardWithoutAdminCannotReachTheAdminPolls(): void
    {
        $poll = $this->addPoll('Robin Example', PollStatus::Draft);
        $this->signIn('lou@example.org');

        self::assertSame('/', $this->request('GET', "/admin/polls/{$poll}")->getHeaderLine('Location'));
        $this->post("/admin/polls/{$poll}/publish");
        self::assertSame('draft', $this->cell($poll, 'status'));
    }

    public function testTheAdminPagesReadNoBallot(): void
    {
        $poll = $this->addPoll('Robin Example');
        $this->service(BallotRepository::class)->cast($poll, $this->kim, Choice::Yes);
        $this->closeAt($poll, '2026-11-03T19:00:00Z');
        $open = $this->addPoll('Sam Example');
        $this->service(BallotRepository::class)->cast($open, $this->kim, Choice::No);
        $this->useClock(self::NOW, logging: true);

        LoggingPdo::reset();
        $this->get('/admin/polls');
        $this->get("/admin/polls/{$open}");
        $this->get("/admin/polls/{$poll}?confirm=reopen");
        $this->post("/admin/polls/{$poll}/reopen");
        $this->get("/admin/polls/{$poll}");
        $this->get('/admin/polls');

        self::assertNotEmpty(LoggingPdo::entries());
        foreach (LoggingPdo::entries() as $entry) {
            self::assertStringNotContainsStringIgnoringCase('ballots', $entry['sql']);
        }
        self::assertSame(1, $this->rows('ballots', $poll));
    }
}
