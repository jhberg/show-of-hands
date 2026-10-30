<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;

final class DelegationTest extends HttpTestCase
{
    private int $kim;

    private int $lou;

    private int $max;

    private int $ned;

    private int $poll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kim = $this->addMember('Kim Example', ['kim@example.org'], group: 'S1');
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->max = $this->addMember('Max Example', ['max@example.org'], board: true, group: null);
        $this->ned = $this->addMember('Ned Example', ['ned@example.org'], group: 'B1');
        $this->poll = $this->addPoll();
    }

    /**
     * @param array<string, string> $body
     */
    private function send(string $path, array $body = [], bool $htmx = false): ResponseInterface
    {
        return $this->request(
            'POST',
            "/polls/{$this->poll}{$path}",
            [CsrfMiddleware::FIELD => $this->session->csrfToken()] + $body,
            headers: $htmx ? ['HX-Request' => 'true'] : [],
        );
    }

    private function page(string $query = ''): string
    {
        return $this->body($this->request('GET', "/polls/{$this->poll}{$query}"));
    }

    private function delegations(): DelegationRepository
    {
        return $this->service(DelegationRepository::class);
    }

    private function hideOpenNames(): void
    {
        $this->service(\PDO::class)->exec("UPDATE settings SET value = '0' WHERE key = 'show_open_names'");
    }

    public function testTheToggleOpensThePanelWithTheMembersTheVoteCanGoTo(): void
    {
        $this->service(BallotRepository::class)->cast($this->poll, $this->lou, Choice::Yes);
        $this->delegations()->delegate($this->poll, $this->ned, $this->max);
        $this->signIn('kim@example.org');

        $closed = $this->page();
        self::assertMatchesRegularExpression('#<input type="hidden" name="delegate" value="1">\s*.*<button class="btn ghost" id="deleg-toggle" aria-expanded="false">.*Stimme an ein anderes Mitglied übertragen</button>#s', $closed);
        self::assertStringNotContainsString('deleg-panel', $closed);

        $open = $this->page('?delegate=1');
        self::assertStringContainsString('id="deleg-toggle" aria-expanded="true"', $open);
        self::assertStringContainsString('<form class="deleg-panel" method="post" action="/polls/' . $this->poll . '/delegation"', $open);
        self::assertStringContainsString('<label for="deleg-to">An wen?</label>', $open);
        // Neither Kim herself nor Ned, who handed his vote on, is offered.
        self::assertStringContainsString('<option value="">Bitte auswählen</option>', $open);
        self::assertStringContainsString("<option value=\"{$this->lou}\">Lou Example (Tenor 1) · hat abgestimmt</option>", $open);
        self::assertStringContainsString("<option value=\"{$this->max}\">Max Example</option>", $open);
        self::assertStringNotContainsString("<option value=\"{$this->kim}\">", $open);
        self::assertStringNotContainsString("<option value=\"{$this->ned}\">", $open);
        self::assertStringContainsString('Eine Person kann höchstens 2 Stimmen zusätzlich erhalten.', $open);
        self::assertStringNotContainsString('Deine eigene Stimme wird dabei zurückgezogen.', $open);
    }

    public function testTheToggleSwapsOnlyMainWithHtmx(): void
    {
        $this->signIn('kim@example.org');

        $response = $this->request('GET', "/polls/{$this->poll}?delegate=1", headers: ['HX-Request' => 'true']);
        $html = $this->body($response);

        self::assertStringStartsWith('<main class="main">', ltrim(preg_replace('/^\s*\{#.*?#\}/s', '', $html) ?? ''));
        self::assertStringNotContainsString('<header', $html);
        self::assertStringContainsString('aria-expanded="true"', $html);
    }

    public function testAMemberWhoVotedLearnsThatDelegatingWithdrawsTheirBallot(): void
    {
        $this->signIn('kim@example.org');
        $this->send('/ballot', ['choice' => 'yes']);

        self::assertStringContainsString('<b>Deine eigene Stimme wird dabei zurückgezogen.</b>', $this->page('?delegate=1'));
    }

    public function testDelegatingWithoutAPersonAsksForOne(): void
    {
        $this->signIn('kim@example.org');

        $response = $this->send('/delegation', ['to' => '']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame("/polls/{$this->poll}?delegate=1", $response->getHeaderLine('Location'));
        $html = $this->page('?delegate=1');
        self::assertStringContainsString('Bitte eine Person auswählen.', $html);

        $htmx = $this->send('/delegation', ['to' => ''], htmx: true);
        self::assertSame(['toast' => 'Bitte eine Person auswählen.'], json_decode($htmx->getHeaderLine('HX-Trigger'), true));
        self::assertStringContainsString('aria-expanded="true"', $this->body($htmx));
        self::assertSame([], $this->delegations()->forPoll($this->poll)->all());
    }

    public function testDelegatingShowsTheNoticeTheTurnoutAndTheHomeChip(): void
    {
        $this->service(BallotRepository::class)->cast($this->poll, $this->lou, Choice::No);
        $this->signIn('kim@example.org');
        $this->send('/ballot', ['choice' => 'yes']);

        $response = $this->send('/delegation', ['to' => (string) $this->lou]);
        self::assertSame("/polls/{$this->poll}", $response->getHeaderLine('Location'));
        self::assertSame(['key' => 'toast.delegation.done', 'params' => ['name' => 'Lou Example'], 'page' => "/polls/{$this->poll}"], $this->session->get('flash'));

        $html = $this->page();
        self::assertStringContainsString('Stimme an Lou Example übertragen.', $html);
        self::assertStringContainsString('<b>Du hast deine Stimme an Lou Example übertragen.</b>', $html);
        self::assertStringContainsString('Lou hat bereits abgestimmt. Wenn du hier selbst auf Ja oder Nein tippst, kommt dein Stimmrecht sofort zu dir zurück und das Gewicht der Stimme von Lou wird angepasst.', $html);
        self::assertStringContainsString('Übertragung zurücknehmen, ohne abzustimmen', $html);
        // The own ballot is gone: no button pressed, no hint, no delegation block.
        self::assertSame(2, substr_count($html, 'aria-pressed="false"'));
        self::assertStringNotContainsString('Tippe auf Ja oder Nein.', $html);
        self::assertStringNotContainsString('deleg-toggle', $html);
        self::assertStringContainsString('<b>2</b> von 4 Stimmrechten abgegeben', $html);
        self::assertStringContainsString('1 Mitglied hat abgestimmt, 1 hat die Stimme übertragen, 2 fehlen noch.', $html);
        self::assertMatchesRegularExpression('#Übertragene Stimmen <span class="n num">1</span></h3>\s*<ul class="dlist">\s*<li>\s*<span>Kim Example</span><svg.*?</svg><span>Lou Example</span></li>#s', $html);

        $home = $this->body($this->request('GET', '/'));
        self::assertStringContainsString('<span class="chip mine">Übertragen an Lou</span>', $home);
    }

    public function testAPendingDelegationIsMarkedAndNotCounted(): void
    {
        $this->signIn('kim@example.org');
        $this->send('/delegation', ['to' => (string) $this->lou]);

        $html = $this->page();
        self::assertStringContainsString('Lou hat noch nicht abgestimmt. Wenn du hier selbst auf Ja oder Nein tippst, kommt dein Stimmrecht sofort zu dir zurück.', $html);
        self::assertStringContainsString('<span>Lou Example</span><span class="chip">noch nicht abgegeben</span>', $html);
        self::assertStringContainsString('<b>0</b> von 4 Stimmrechten abgegeben', $html);
    }

    public function testTheRecipientSeesTheWeightOfTheirVote(): void
    {
        $this->delegations()->delegate($this->poll, $this->kim, $this->lou);
        $this->delegations()->delegate($this->poll, $this->ned, $this->lou);
        $this->signIn('lou@example.org');

        $html = $this->page();
        self::assertStringContainsString('<div class="weight"><span class="x">×3</span><span><b>Du stimmst mit 3 Stimmrechten.</b> Kim Example und Ned Example haben dir die Stimmen übertragen. Deine Entscheidung zählt 3-fach, solange die Übertragung besteht.</span></div>', $html);
        // A member who holds votes cannot hand their own on.
        self::assertStringNotContainsString('deleg-toggle', $html);

        $this->send('/ballot', ['choice' => 'yes']);
        self::assertStringContainsString('<b>3</b> von 4 Stimmrechten abgegeben', $this->page());
    }

    public function testOneDelegatedVoteReadsInTheSingular(): void
    {
        $this->delegations()->delegate($this->poll, $this->kim, $this->lou);
        $this->signIn('lou@example.org');

        self::assertStringContainsString('<b>Du stimmst mit 2 Stimmrechten.</b> Kim Example hat dir die Stimme übertragen.', $this->page());
    }

    public function testVotingTakesTheDelegationBack(): void
    {
        $this->service(BallotRepository::class)->cast($this->poll, $this->lou, Choice::No);
        $this->signIn('kim@example.org');
        $this->send('/delegation', ['to' => (string) $this->lou]);
        $this->page();

        $this->send('/ballot', ['choice' => 'yes']);
        self::assertSame(['key' => 'toast.ballot.reclaimed', 'params' => ['name' => 'Lou'], 'page' => "/polls/{$this->poll}"], $this->session->get('flash'));
        $html = $this->page();
        self::assertStringContainsString('Stimme registriert: Ja. Die Übertragung an Lou ist aufgehoben.', $html);
        self::assertStringNotContainsString('Du hast deine Stimme an', $html);
        self::assertStringContainsString('<b>2</b> von 4 Stimmrechten abgegeben', $html);

        $this->send('/delegation', ['to' => (string) $this->lou]);
        $htmx = $this->send('/ballot', ['choice' => 'no'], htmx: true);
        self::assertSame(['toast' => 'Stimme registriert: Nein. Die Übertragung an Lou ist aufgehoben.'], json_decode($htmx->getHeaderLine('HX-Trigger'), true));
    }

    public function testRevokingGivesTheVoteBack(): void
    {
        $this->signIn('kim@example.org');
        $this->send('/delegation', ['to' => (string) $this->lou]);
        $this->page();

        $response = $this->send('/delegation/revoke');
        self::assertSame("/polls/{$this->poll}", $response->getHeaderLine('Location'));
        $html = $this->page();
        self::assertStringContainsString('Übertragung zurückgenommen. Dein Stimmrecht liegt wieder bei dir.', $html);
        self::assertStringContainsString('Tippe auf Ja oder Nein.', $html);
        self::assertStringContainsString('deleg-toggle', $html);
        self::assertSame([], $this->delegations()->forPoll($this->poll)->all());

        // Nothing left to take back: no toast.
        $this->send('/delegation/revoke');
        self::assertNull($this->session->get('flash'));
    }

    public function testAStalePageGetsARefusal(): void
    {
        $this->signIn('kim@example.org');
        // Lou delegated to Ned after Kim's page offered Lou.
        $this->delegations()->delegate($this->poll, $this->lou, $this->ned);

        $response = $this->send('/delegation', ['to' => (string) $this->lou]);

        self::assertSame("/polls/{$this->poll}?delegate=1", $response->getHeaderLine('Location'));
        self::assertStringContainsString('Die Übertragung ist so nicht möglich.', $this->page('?delegate=1'));
        self::assertNull($this->delegations()->forPoll($this->poll)->recipientOf($this->kim));
    }

    public function testNoDelegationBlockWhileDelegationIsOff(): void
    {
        $this->delegations()->delegate($this->poll, $this->kim, $this->lou);
        $this->service(\PDO::class)->exec("UPDATE settings SET value = '0' WHERE key = 'delegation_enabled'");
        $this->signIn('ned@example.org');
        self::assertStringNotContainsString('deleg-toggle', $this->page('?delegate=1'));
        self::assertSame("/polls/{$this->poll}?delegate=1", $this->send('/delegation', ['to' => (string) $this->max])->getHeaderLine('Location'));
        self::assertNull($this->delegations()->forPoll($this->poll)->recipientOf($this->ned));

        // An existing delegation stays, and can still be taken back.
        $this->signIn('kim@example.org');
        self::assertStringContainsString('Übertragung zurücknehmen, ohne abzustimmen', $this->page());
        $this->send('/delegation/revoke');
        self::assertSame([], $this->delegations()->forPoll($this->poll)->all());
    }

    public function testHiddenNamesHideWhoHasVotedFromMembersOnly(): void
    {
        $this->service(BallotRepository::class)->cast($this->poll, $this->lou, Choice::Yes);
        $this->delegations()->delegate($this->poll, $this->ned, $this->max);
        $this->delegations()->delegate($this->poll, $this->kim, $this->lou);
        $this->hideOpenNames();

        $this->signIn('kim@example.org');
        $html = $this->page();
        self::assertStringNotContainsString('noch nicht abgegeben', $html);
        self::assertStringNotContainsString('Lou hat bereits abgestimmt', $html);
        self::assertStringContainsString('<b>Du hast deine Stimme an Lou Example übertragen.</b>', $html);
        self::assertStringContainsString('Wenn du hier selbst auf Ja oder Nein tippst, kommt dein Stimmrecht sofort zu dir zurück.', $html);
        // The list of delegations itself stays visible to all members.
        self::assertStringContainsString('<span>Ned Example</span>', $html);
        $this->send('/delegation/revoke');
        self::assertStringNotContainsString('hat abgestimmt</option>', $this->page('?delegate=1'));

        $this->signIn('max@example.org');
        self::assertStringContainsString('noch nicht abgegeben', $this->page());
        $this->delegations()->revoke($this->poll, $this->ned);
        self::assertStringContainsString('Lou Example (Tenor 1) · hat abgestimmt</option>', $this->page('?delegate=1'));
    }

    public function testDelegationInAClosedPollIsRefused(): void
    {
        $closed = $this->addPoll(status: PollStatus::Closed);
        $this->signIn('kim@example.org');

        $requests = [['/delegation', ['to' => (string) $this->lou]], ['/delegation', ['to' => '']], ['/delegation/revoke', []]];
        foreach ($requests as [$path, $body]) {
            $this->request('POST', "/polls/{$closed}{$path}", [CsrfMiddleware::FIELD => $this->session->csrfToken()] + $body);
            $flash = $this->session->get('flash');
            self::assertIsArray($flash);
            self::assertSame('toast.poll_closed', $flash['key']);
            $this->request('GET', "/polls/{$closed}");
        }
        self::assertSame([], $this->delegations()->forPoll($closed)->all());
    }

    public function testDelegationNeedsTheCsrfToken(): void
    {
        $this->signIn('kim@example.org');

        $response = $this->request('POST', "/polls/{$this->poll}/delegation", ['to' => (string) $this->lou]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->delegations()->forPoll($this->poll)->all());
    }
}
