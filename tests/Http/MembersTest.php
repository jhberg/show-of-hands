<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\RollRepository;
use ShowOfHands\Tests\FixedClock;

final class MembersTest extends HttpTestCase
{
    private int $kim;

    private int $lou;

    private int $max;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at('2026-11-03T20:05:00Z')]);
        $this->kim = $this->addMember('Kim Example', ['kim@example.org', 'kim@example.net'], admin: true, board: true, group: 'S1');
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->max = $this->addMember('Max Example', ['max@example.org'], board: true, group: null);
        $this->signIn('kim@example.org');
    }

    /**
     * @param array<string, string> $body
     */
    private function send(string $path, array $body = [], bool $htmx = false): ResponseInterface
    {
        return $this->post('/admin/members' . $path, $body, $htmx);
    }

    private function page(string $query = ''): string
    {
        return $this->body($this->request('GET', '/admin/members' . $query));
    }

    private function members(): MemberRepository
    {
        return $this->service(MemberRepository::class);
    }

    /**
     * The app's time and time zone, as the repository's update takes them.
     *
     * @return array{\DateTimeImmutable, \DateTimeZone}
     */
    private function now(): array
    {
        return [$this->service(Clock::class)->now(), new \DateTimeZone($this->service(Config::class)->timezone)];
    }

    private function number(string $sql, int $poll): int
    {
        $statement = $this->service(\PDO::class)->prepare($sql);
        $statement->execute([$poll]);

        return (int) $statement->fetchColumn();
    }

    public function testMembersWithoutTheAdminRoleAreSentHome(): void
    {
        $this->signIn('lou@example.org');

        self::assertSame('/', $this->request('GET', '/admin/members')->getHeaderLine('Location'));
        self::assertSame('/', $this->send('/' . $this->max . '/delete')->getHeaderLine('Location'));
        self::assertNotNull($this->members()->find($this->max));
    }

    public function testListsMembersWithTheirAddressesRolesAndCounts(): void
    {
        $html = $this->page();

        self::assertStringContainsString('<a href="/admin/members" class="on" aria-current="page">Mitglieder</a>', $html);
        self::assertStringContainsString('Kim Example <span class="chip mine">du</span>', $html);
        self::assertStringContainsString('<code>kim@example.net</code>', $html);
        self::assertStringContainsString('<span class="chip">Tenor 1</span>', $html);
        // The pills read right to left: voice group, admin, board.
        self::assertStringContainsString('<span class="tags"><span class="chip open">Vorstand</span><span class="chip closed">Verwaltung</span><span class="chip">Sopran 1</span></span>', $html);
        // The actions are icons that say what they do to screen readers and in a tooltip.
        self::assertMatchesRegularExpression('#id="m-' . $this->lou . '-edit" href="[^"]+" hx-get="[^"]+" hx-replace-url="true" aria-label="Bearbeiten" title="Bearbeiten"><svg#', $html);
        self::assertMatchesRegularExpression('#id="m-' . $this->lou . '-remove" href="[^"]+" hx-get="[^"]+" hx-replace-url="true" aria-label="Entfernen" title="Entfernen"><svg#', $html);
        self::assertStringContainsString('<b>3</b> Stimmberechtigte', $html);
        self::assertStringContainsString('<b>2</b> im Vorstand', $html);
        self::assertStringContainsString('<b>1</b> mit Verwaltungszugang', $html);
        // Admins cannot remove themselves, so their own row offers no removal.
        self::assertStringContainsString('?delete=' . $this->lou, $html);
        self::assertStringNotContainsString('?delete=' . $this->kim, $html);
        // Voice group order first: S1, T1, then members without a group.
        self::assertLessThan(strpos($html, 'Max Example'), strpos($html, 'Lou Example'));
    }

    public function testSearchesNamesAndAddressesWithoutJavaScript(): void
    {
        $html = $this->page('?q=LOU');
        self::assertStringContainsString('Lou Example', $html);
        self::assertStringNotContainsString('Max Example', $html);
        self::assertStringContainsString('value="LOU"', $html);

        self::assertStringContainsString('Kim Example', $this->page('?q=example.net'));
        self::assertStringContainsString('Keine Treffer.', $this->page('?q=nobody'));
    }

    public function testSearchWithHtmxSwapsOnlyTheList(): void
    {
        $html = $this->body($this->request('GET', '/admin/members?q=max', headers: ['HX-Request' => 'true', 'HX-Target' => 'member-list']));

        self::assertStringStartsWith('<ul class="alist panel" id="member-list">', trim($html));
        self::assertStringContainsString('Max Example', $html);
        self::assertStringNotContainsString('Lou Example', $html);
    }

    public function testTheSearchKeepsAnOpenFormInItsUrl(): void
    {
        self::assertStringContainsString('<input type="hidden" name="edit" value="' . $this->lou . '">', $this->page('?edit=' . $this->lou));
        self::assertStringContainsString('<input type="hidden" name="add" value="1">', $this->page('?add=1'));
        self::assertStringNotContainsString('type="hidden" name="add"', $this->page());
    }

    public function testTheAddFormReplacesTheAddButton(): void
    {
        self::assertStringContainsString('>Mitglied hinzufügen</a>', $this->page());

        $html = $this->page('?add=1');
        self::assertStringNotContainsString('>Mitglied hinzufügen</a>', $html);
        self::assertStringContainsString('<h2>Mitglied hinzufügen</h2>', $html);
        self::assertStringContainsString('E-Mail-Adresse (Example Choir-Konto)', $html);
        self::assertMatchesRegularExpression('/id="mf-name"[^>]*autofocus/', $html);
    }

    public function testAddsAMemberWhoCanThenSignIn(): void
    {
        $response = $this->send('', ['name' => ' Ned Example ', 'email' => 'Ned@Example.org', 'email2' => '', 'group' => 'B1', 'board' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/members', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Ned Example hinzugefügt.', $this->page());
        $found = $this->members()->findByEmail('ned@example.org');
        self::assertNotNull($found);
        self::assertSame('B1', $found[0]->voiceGroupKey);
        self::assertTrue($found[0]->isBoard);
        self::assertFalse($found[0]->isAdmin);
    }

    public function testAddsAndClearsADisplayNameTheListShowsBesideTheFullName(): void
    {
        $this->send('', ['name' => 'Nedwin Example', 'display_name' => ' Ned E. ', 'email' => 'ned@example.org', 'group' => 'B1']);

        $ned = $this->members()->findByEmail('ned@example.org')[0] ?? null;
        self::assertNotNull($ned);
        self::assertSame('Ned E.', $ned->displayName);
        $this->addMember('Zed Example', ['zed@example.org'], group: 'B1', displayName: 'Abe Z.');
        $page = $this->page();
        self::assertStringContainsString('<b>Nedwin Example</b><span class="meta">Anzeigename: Ned E.</span>', $page);
        self::assertLessThan(strpos($page, 'Zed Example'), strpos($page, 'Nedwin Example'), 'The admin list keeps the full-name order.');
        self::assertStringContainsString('Nedwin Example', $this->page('?q=ned+e.'));
        self::assertStringContainsString('value="Ned E."', $this->page('?edit=' . $ned->id));

        $this->send('/' . $ned->id, ['name' => 'Nedwin Example', 'display_name' => '', 'email' => 'ned@example.org', 'group' => 'B1']);

        self::assertNull($this->members()->find($ned->id)?->displayName);
        self::assertStringNotContainsString('Anzeigename: Ned E.', $this->page());
    }

    public function testARefusedSaveKeepsTheDisplayNameAsEntered(): void
    {
        $response = $this->send('', ['name' => '', 'display_name' => 'Ned E.', 'email' => 'ned@example.org', 'group' => 'B1']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('value="Ned E."', $this->body($response));
    }

    public function testAddingWithHtmxSwapsThePanelAndShowsEveryMember(): void
    {
        $response = $this->send('', ['name' => 'Ned Example', 'email' => 'ned@example.org', 'group' => 'B1'], htmx: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['toast' => 'Ned Example hinzugefügt.'], json_decode($response->getHeaderLine('HX-Trigger'), true));
        self::assertSame('/admin/members', $response->getHeaderLine('HX-Replace-Url'));
        $html = $this->body($response);
        self::assertStringContainsString('<div id="members"', $html);
        self::assertStringNotContainsString('<html', $html);
        self::assertStringNotContainsString('id="member-form"', $html);
        self::assertStringContainsString('Ned Example', $html);
        self::assertStringContainsString('Lou Example', $html);
    }

    public function testRemovingWithHtmxKeepsTheSearch(): void
    {
        $response = $this->send('/' . $this->lou . '/delete', ['q' => 'example.org'], htmx: true);

        self::assertSame('/admin/members?q=example.org', $response->getHeaderLine('HX-Replace-Url'));
        self::assertStringContainsString('value="example.org"', $this->body($response));
        self::assertStringNotContainsString('Lou Example', $this->body($response));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'no name' => [['name' => '', 'email' => 'ned@example.org'], 'name', 'Name und mindestens eine gültige E-Mail-Adresse sind nötig.'];
        yield 'invalid address' => [['name' => 'Ned Example', 'email' => 'ned'], 'email', 'Name und mindestens eine gültige E-Mail-Adresse sind nötig.'];
        yield 'same addresses' => [['name' => 'Ned Example', 'email' => 'ned@example.org', 'email2' => 'NED@example.org'], 'email2', 'Die beiden Adressen sind gleich.'];
        yield 'address of another member' => [['name' => 'Ned Example', 'email' => 'ned@example.org', 'email2' => 'Lou@example.org'], 'email2', 'Eine dieser Adressen ist schon hinterlegt: Lou Example.'];
    }

    /**
     * @param array<string, string> $body
     */
    #[DataProvider('refusals')]
    public function testARefusedAddShowsTheFormAgainWithTheMessageAtItsField(array $body, string $field, string $message): void
    {
        $response = $this->send('', $body + ['group' => 'B1']);

        self::assertSame(422, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('<p class="field-error" id="mf-' . $field . '-error">' . htmlspecialchars($message) . '</p>', $html);
        self::assertStringContainsString('class="toast on" role="status" aria-live="polite">' . htmlspecialchars($message), $html);
        self::assertMatchesRegularExpression('/id="mf-' . $field . '"[^>]*autofocus[^>]*aria-invalid="true"/', $html);
        self::assertStringContainsString('value="' . htmlspecialchars($body['name']) . '"', $html);
        self::assertCount(3, $this->members()->all('de'));
    }

    public function testARefusedSaveWithHtmxIsSwappedInWithTheToast(): void
    {
        $response = $this->send('', ['name' => '', 'email' => ''], htmx: true);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Name und mindestens', $response->getHeaderLine('HX-Trigger'));
        self::assertStringContainsString('id="member-form"', $this->body($response));
    }

    public function testEditsAMemberAndTheirAddresses(): void
    {
        $form = $this->page('?edit=' . $this->lou);
        self::assertStringContainsString('<h2>Mitglied bearbeiten</h2>', $form);
        self::assertStringContainsString('value="lou@example.org"', $form);
        self::assertStringContainsString('<option value="T1" selected>', $form);

        $response = $this->send('/' . $this->lou, ['name' => 'Lou Renamed', 'email' => 'lou@example.org', 'email2' => 'lou@example.net', 'group' => 'T2', 'admin' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('Mitglied gespeichert.', $this->page());
        $lou = $this->members()->find($this->lou);
        self::assertNotNull($lou);
        self::assertSame('Lou Renamed', $lou->name);
        self::assertSame('T2', $lou->voiceGroupKey);
        self::assertTrue($lou->isAdmin);
        self::assertSame(['lou@example.org', 'lou@example.net'], $this->members()->emailsOf($this->lou));
    }

    public function testAMemberWithoutAGroupKeepsNoneUntilOneIsChosen(): void
    {
        self::assertStringContainsString('<option value="" selected>Ohne Gruppe</option>', $this->page('?edit=' . $this->max));
        self::assertStringNotContainsString('Ohne Gruppe', $this->page('?edit=' . $this->lou));

        $this->send('/' . $this->max, ['name' => 'Max Example', 'email' => 'max@example.org', 'group' => '', 'board' => '1']);
        self::assertNull($this->members()->find($this->max)?->voiceGroupKey);
    }

    public function testAnEditMayKeepTheMembersOwnAddressButNotTakeAnother(): void
    {
        $response = $this->send('/' . $this->lou, ['name' => 'Lou Example', 'email' => 'kim@example.net', 'group' => 'T1']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Eine dieser Adressen ist schon hinterlegt: Kim Example.', $this->body($response));
        self::assertSame(['lou@example.org'], $this->members()->emailsOf($this->lou));
    }

    public function testAdminsKeepTheirOwnAdminRole(): void
    {
        $this->send('/' . $this->kim, ['name' => 'Kim Example', 'email' => 'kim@example.org', 'group' => 'S1']);

        self::assertStringContainsString('Den eigenen Verwaltungszugang kannst du nicht entfernen.', $this->page());
        $kim = $this->members()->find($this->kim);
        self::assertNotNull($kim);
        self::assertTrue($kim->isAdmin);
        // The board role is theirs to drop, and so is the second address.
        self::assertFalse($kim->isBoard);
        self::assertSame(['kim@example.org'], $this->members()->emailsOf($this->kim));
    }

    public function testAdminsKeepTheAddressTheySignedInWith(): void
    {
        $response = $this->send('/' . $this->kim, ['name' => 'Kim Example', 'email' => 'kim@exmaple.org', 'email2' => 'kim@example.net', 'group' => 'S1', 'admin' => '1']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p class="field-error" id="mf-email-error">Die Adresse, mit der du gerade angemeldet bist, kannst du nicht entfernen.</p>', $this->body($response));
        self::assertSame(['kim@example.org', 'kim@example.net'], $this->members()->emailsOf($this->kim));
        self::assertSame(200, $this->request('GET', '/admin/members')->getStatusCode());
    }

    public function testAnUnknownMemberCannotBeSavedOrRemoved(): void
    {
        self::assertSame(404, $this->send('/999', ['name' => 'Ned Example', 'email' => 'ned@example.org'])->getStatusCode());
        self::assertSame(404, $this->send('/999/delete')->getStatusCode());
    }

    public function testTheRemovalAsksFirst(): void
    {
        $html = $this->page('?delete=' . $this->lou);

        self::assertStringContainsString('<b>Lou Example entfernen?</b> Die Person kann sich danach nicht mehr anmelden.', $html);
        self::assertStringContainsString('action="/admin/members/' . $this->lou . '/delete"', $html);
        self::assertStringNotContainsString('/delete"', $this->page('?delete=' . $this->kim));
    }

    public function testAdminsCannotRemoveThemselves(): void
    {
        self::assertSame(403, $this->send('/' . $this->kim . '/delete')->getStatusCode());
        self::assertNotNull($this->members()->find($this->kim));
    }

    public function testARemovedMemberIsSignedOutAndCannotSignInAgain(): void
    {
        $response = $this->send('/' . $this->lou . '/delete', ['q' => 'lou']);

        self::assertSame('/admin/members?q=lou', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Lou Example entfernt.', $this->page());
        self::assertNull($this->members()->findByEmail('lou@example.org'));
        self::assertSame(303, $this->signIn('lou@example.org')->getStatusCode());
        self::assertStringContainsString('Diese Adresse ist nicht als Mitglied hinterlegt.', $this->body($this->request('GET', '/login')));
    }

    public function testReplacingAnAddressSignsOutWhoeverUsedIt(): void
    {
        $this->send('/' . $this->kim, ['name' => 'Kim Example', 'email' => 'kim@example.org', 'group' => 'S1', 'admin' => '1']);
        self::assertSame(200, $this->request('GET', '/admin/members')->getStatusCode());

        $this->signIn('kim@example.net');
        $this->service(MemberRepository::class)->update($this->kim, 'Kim Example', ['kim@example.org'], 'S1', false, true, null, ...$this->now());

        self::assertSame('/login', $this->request('GET', '/')->getHeaderLine('Location'));
    }

    public function testAnAddressMovedToAnotherMemberDoesNotTakeTheSessionAlong(): void
    {
        $this->signIn('lou@example.org');
        $members = $this->members();
        $members->update($this->lou, 'Lou Example', ['lou@example.net'], 'T1', false, false, null, ...$this->now());
        $members->update($this->max, 'Max Example', ['max@example.org', 'lou@example.org'], null, true, false, null, ...$this->now());

        self::assertSame('/login', $this->request('GET', '/')->getHeaderLine('Location'));
    }

    /**
     * Removing a member who took part in an open poll would take a cast vote out of its count, so
     * an admin could remove votes they expect to dislike.
     */
    public function testAMemberWhoTookPartInAnOpenPollStaysWhileItIsOpen(): void
    {
        $ballots = $this->service(BallotRepository::class);
        $poll = $this->addPoll();
        $ned = $this->addMember('Ned Example', ['ned@example.org']);
        $ballots->cast($poll, $this->lou, Choice::Yes);
        self::assertNull($this->service(DelegationRepository::class)->delegate($poll, $ned, $this->max));

        // Voted, delegated, and received a vote, each in the open poll.
        foreach ([$this->lou, $ned, $this->max] as $member) {
            $response = $this->send('/' . $member . '/delete', htmx: true);
            self::assertStringContainsString('an einer laufenden Abstimmung teilgenommen und kann erst ab dem Tag nach ihrem Abschluss entfernt werden.', $response->getHeaderLine('HX-Trigger'));
            self::assertNotNull($this->members()->find($member));
        }
        self::assertSame(Choice::Yes, $ballots->choice($poll, $this->lou));
        self::assertSame(1, $this->number('SELECT count(*) FROM participation WHERE poll_id = ?', $poll));
        self::assertSame(1, $this->number('SELECT count(*) FROM delegations WHERE poll_id = ?', $poll));

        // A reopened poll that is open again holds them the same way.
        $this->service(\PDO::class)->prepare('UPDATE polls SET reopen_count = 1 WHERE id = ?')->execute([$poll]);
        $response = $this->send('/' . $this->lou . '/delete', htmx: true);
        self::assertStringContainsString('an einer laufenden Abstimmung teilgenommen', $response->getHeaderLine('HX-Trigger'));
        self::assertNotNull($this->members()->find($this->lou));
    }

    public function testAnOpenPollOutweighsAPollClosedToday(): void
    {
        $ballots = $this->service(BallotRepository::class);
        $closed = $this->addPoll();
        $ballots->cast($closed, $this->lou, Choice::Yes);
        $this->service(PollCloser::class)->close($closed, new \DateTimeImmutable('2026-11-03T19:00:00Z'));
        $ballots->cast($this->addPoll(subject: 'Sam Example'), $this->lou, Choice::No);

        $response = $this->send('/' . $this->lou . '/delete', htmx: true);

        self::assertStringContainsString('an einer laufenden Abstimmung teilgenommen', $response->getHeaderLine('HX-Trigger'));
    }

    public function testAMemberWithoutAPartInAnOpenPollCanGo(): void
    {
        $ballots = $this->service(BallotRepository::class);
        $poll = $this->addPoll();
        $ballots->cast($poll, $this->kim, Choice::No);

        $response = $this->send('/' . $this->lou . '/delete', htmx: true);

        self::assertStringContainsString('Lou Example entfernt.', $response->getHeaderLine('HX-Trigger'));
        self::assertNull($this->members()->find($this->lou));
        self::assertSame(Choice::No, $ballots->choice($poll, $this->kim));
        self::assertSame(1, $this->number('SELECT count(*) FROM participation WHERE poll_id = ?', $poll));
    }

    /**
     * An admin may still reopen a poll closed today. A recount without the member would reveal
     * their choice, so they stay until the next day.
     */
    public function testAMemberWhoTookPartInAPollClosedTodayStaysUntilTomorrow(): void
    {
        $ballots = $this->service(BallotRepository::class);
        $poll = $this->addPoll();
        $ballots->cast($poll, $this->lou, Choice::Yes);
        $ballots->cast($poll, $this->kim, Choice::No);
        self::assertNull($this->service(DelegationRepository::class)->delegate($poll, $this->max, $this->kim));
        // 20:00 in Berlin, the same day as the clock's 21:05.
        $this->service(PollCloser::class)->close($poll, new \DateTimeImmutable('2026-11-03T19:00:00Z'));

        // Voted, and delegated, each in a poll closed today.
        foreach ([$this->lou, $this->max] as $member) {
            $response = $this->send('/' . $member . '/delete', htmx: true);
            self::assertStringContainsString('an einer heute abgeschlossenen Abstimmung teilgenommen und kann erst ab morgen entfernt werden.', $response->getHeaderLine('HX-Trigger'));
            self::assertNotNull($this->members()->find($member));
        }
        self::assertSame(2, $this->number('SELECT count(*) FROM ballots WHERE poll_id = ?', $poll));

        // Past midnight in Berlin, the poll can no longer be reopened.
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at('2026-11-03T23:30:00Z')]);
        $this->send('/' . $this->lou . '/delete');

        self::assertNull($this->members()->find($this->lou));
        // The closed poll keeps the turnout and the tally of its close.
        $roll = $this->service(RollRepository::class)->turnout($poll, 'de');
        self::assertSame(3, $roll->eligible);
        self::assertSame(3, $roll->castWeight);
        self::assertSame(1, $this->number('SELECT yes_weight FROM polls WHERE id = ?', $poll));
    }

    public function testAMemberWithoutAPartInAPollClosedTodayCanGo(): void
    {
        $poll = $this->addPoll();
        $this->service(BallotRepository::class)->cast($poll, $this->kim, Choice::No);
        $this->service(PollCloser::class)->close($poll, new \DateTimeImmutable('2026-11-03T19:00:00Z'));

        $this->send('/' . $this->lou . '/delete');

        self::assertNull($this->members()->find($this->lou));
    }

    /**
     * A new address would let its owner sign in as the member and read or change their vote.
     * While any poll is open, no existing member gets one, whether they voted or not.
     */
    public function testNoMemberGetsANewAddressWhileAPollIsOpen(): void
    {
        $this->addPoll();
        $message = 'Solange eine Abstimmung läuft, lassen sich keine Adressen hinzufügen oder ersetzen. Das geht wieder ab dem Tag nach ihrem Abschluss.';

        $response = $this->send('/' . $this->lou, ['name' => 'Lou Example', 'email' => 'lou@example.org', 'email2' => 'kim@example.com', 'group' => 'T1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p class="field-error" id="mf-email2-error">' . $message . '</p>', $this->body($response));

        // Replacing an address gives the member a new one too.
        $response = $this->send('/' . $this->lou, ['name' => 'Lou Example', 'email' => 'lou@example.net', 'group' => 'T1'], htmx: true);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['toast' => $message], json_decode($response->getHeaderLine('HX-Trigger'), true));
        self::assertStringContainsString('<p class="field-error" id="mf-email-error">', $this->body($response));

        self::assertSame(['lou@example.org'], $this->members()->emailsOf($this->lou));
        self::assertNull($this->members()->findByEmail('kim@example.com'));
    }

    public function testNoMemberGetsANewAddressOnTheDayAPollClosed(): void
    {
        $poll = $this->addPoll();
        $this->service(PollCloser::class)->close($poll, new \DateTimeImmutable('2026-11-03T19:00:00Z'));
        $body = ['name' => 'Lou Example', 'email' => 'lou@example.org', 'email2' => 'kim@example.com', 'group' => 'T1'];

        $response = $this->send('/' . $this->lou, $body);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Heute wurde eine Abstimmung abgeschlossen. Adressen hinzufügen oder ersetzen geht erst ab morgen wieder.', $this->body($response));
        self::assertSame(['lou@example.org'], $this->members()->emailsOf($this->lou));

        // Past midnight in Berlin, the poll can no longer be reopened.
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at('2026-11-03T23:30:00Z')]);
        self::assertSame(303, $this->send('/' . $this->lou, $body)->getStatusCode());
        self::assertSame(['lou@example.org', 'kim@example.com'], $this->members()->emailsOf($this->lou));
    }

    public function testAddressesCanBeAddedWhileNoPollIsOpen(): void
    {
        $this->addPoll(status: PollStatus::Draft);

        $response = $this->send('/' . $this->lou, ['name' => 'Lou Example', 'email' => 'lou@example.net', 'email2' => 'lou@example.com', 'group' => 'T1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['lou@example.net', 'lou@example.com'], $this->members()->emailsOf($this->lou));
    }

    public function testWhileAPollIsOpenAddressesCanBeRemovedAndTheOtherFieldsSaved(): void
    {
        $poll = $this->addPoll();
        $this->service(BallotRepository::class)->cast($poll, $this->kim, Choice::Yes);

        // The admin's own form: the second address goes, the roles and the group change.
        $response = $this->send('/' . $this->kim, ['name' => 'Kim Renamed', 'display_name' => 'Kim R.', 'email' => 'Kim@Example.org', 'group' => 'B1', 'admin' => '1'], htmx: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Mitglied gespeichert.', $response->getHeaderLine('HX-Trigger'));
        $kim = $this->members()->find($this->kim);
        self::assertSame(['Kim Renamed', 'Kim R.', 'B1', false], [$kim?->name, $kim?->displayName, $kim?->voiceGroupKey, $kim?->isBoard]);
        self::assertSame(['kim@example.org'], $this->members()->emailsOf($this->kim));
    }

    /**
     * The choir uses the app when votes are due, so admins often notice only then that members
     * are missing from the list.
     */
    public function testNewMembersCanBeAddedWhileAPollIsOpen(): void
    {
        $this->addPoll();

        $response = $this->send('', ['name' => 'Ned Example', 'email' => 'ned@example.org', 'email2' => 'ned@example.net', 'group' => 'T1']);

        self::assertSame(303, $response->getStatusCode());
        $ned = $this->members()->findByEmail('ned@example.net')[0] ?? null;
        self::assertSame('Ned Example', $ned?->name);
    }

    public function testTheRefusalReadsInEnglish(): void
    {
        $this->addPoll();
        $this->members()->setLocale($this->kim, 'en');

        $response = $this->send('/' . $this->kim, ['name' => 'Kim Example', 'email' => 'kim@example.org', 'email2' => 'kim@example.com', 'group' => 'S1', 'admin' => '1', 'board' => '1']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('<p class="field-error" id="mf-email2-error">While a poll is open, addresses cannot be added or replaced. That works again from the day after it closes.</p>', $this->body($response));
        self::assertSame(['kim@example.org', 'kim@example.net'], $this->members()->emailsOf($this->kim));
    }

    public function testTheListReadsTheEnglishWording(): void
    {
        $this->members()->setLocale($this->kim, 'en');

        $html = $this->page('?delete=' . $this->lou);
        self::assertStringContainsString('<b>3</b> eligible voters', $html);
        self::assertStringContainsString('<span class="chip mine">you</span>', $html);
        self::assertStringContainsString('<b>Remove Lou Example?</b>', $html);
    }
}
