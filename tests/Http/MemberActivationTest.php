<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\DelegationRefusal;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberInactiveException;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Tests\FixedClock;

final class MemberActivationTest extends HttpTestCase
{
    private int $kim;

    private int $lou;

    private int $max;

    private int $ida;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at('2026-11-03T20:05:00Z')]);
        $this->kim = $this->addMember('Kim Example', ['kim@example.org'], admin: true, board: true);
        $this->lou = $this->addMember('Lou Example', ['lou@example.org'], group: 'T1');
        $this->max = $this->addMember('Max Example', ['max@example.org'], group: 'B1');
        $this->ida = $this->addMember('Ida Example', ['ida@example.org'], group: 'A1');
        $this->signIn('kim@example.org');
    }

    private function setActive(int $member, bool $active, bool $htmx = false): ResponseInterface
    {
        return $this->post('/admin/members/' . $member . '/active', ['active' => $active ? '1' : '0'], $htmx);
    }

    private function isActive(int $member): bool
    {
        $found = $this->service(MemberRepository::class)->find($member);
        assert($found !== null);

        return $found->isActive;
    }

    public function testAnInactiveMemberIsMarkedAndLeavesTheEligibleVoters(): void
    {
        $response = $this->setActive($this->lou, false, htmx: true);

        self::assertSame(['toast' => 'Lou Example ist jetzt inaktiv und zählt nicht mehr zu den Stimmberechtigten.'], json_decode($response->getHeaderLine('HX-Trigger'), true));
        $html = $this->body($response);
        self::assertStringContainsString('<b>3</b> Stimmberechtigte', $html);
        self::assertStringContainsString(' · <b>1</b> inaktiv', $html);
        self::assertStringContainsString('<span class="chip">inaktiv</span>', $html);
        self::assertStringContainsString('<button class="btn sm" id="m-' . $this->lou . '-active">Aktiv setzen</button>', $html);
        self::assertFalse($this->isActive($this->lou));
    }

    public function testSettingAMemberInactiveDuringAPollChangesItsEligibleVotersAndItsCount(): void
    {
        $poll = $this->addPoll();
        $ballots = $this->service(BallotRepository::class);
        $ballots->cast($poll, $this->kim, Choice::Yes);
        $ballots->cast($poll, $this->max, Choice::Yes);
        self::assertStringContainsString('<b>2</b> von 4 Stimmrechten abgegeben', $this->body($this->request('GET', '/polls/' . $poll)));

        $this->setActive($this->lou, false);

        $html = $this->body($this->request('GET', '/polls/' . $poll));
        self::assertStringContainsString('<b>2</b> von 3 Stimmrechten abgegeben', $html);
        self::assertStringNotContainsString('<span class="name">Lou Example</span>', $html);
        // Two thirds of three: two Yes votes admit, where two of four would not have.
        $this->service(PollCloser::class)->close($poll, new \DateTimeImmutable('2026-11-03T20:00:00Z'));
        $tally = $this->service(PollRepository::class)->find($poll)?->tally;
        self::assertSame(3, $tally?->eligible);
        self::assertTrue($tally->isAccepted());
    }

    public function testTheAdminPollPagesCountOnlyActiveMembers(): void
    {
        $poll = $this->addPoll();
        $this->setActive($this->lou, false);

        self::assertMatchesRegularExpression('#<b>0</b>\\s*/\\s*3#', $this->body($this->request('GET', '/admin/polls')));
        $detail = $this->body($this->request('GET', '/admin/polls/' . $poll));
        self::assertStringContainsString('<b>0</b> von 3 Stimmrechten abgegeben', $detail);
        self::assertStringNotContainsString('<span class="name">Lou Example</span>', $detail);
    }

    public function testAMemberActiveAgainCountsAgain(): void
    {
        $poll = $this->addPoll();
        $this->setActive($this->lou, false);

        $response = $this->setActive($this->lou, true, htmx: true);

        self::assertStringContainsString('Lou Example ist wieder aktiv und stimmberechtigt.', $response->getHeaderLine('HX-Trigger'));
        self::assertTrue($this->isActive($this->lou));
        self::assertStringContainsString('<b>0</b> von 4 Stimmrechten abgegeben', $this->body($this->request('GET', '/polls/' . $poll)));
    }

    /**
     * Setting a member inactive would take their cast vote out of the count, so an admin could
     * drop votes they expect to dislike.
     */
    public function testAMemberWhoTookPartInAnOpenPollStaysActiveUntilItCloses(): void
    {
        $poll = $this->addPoll();
        $this->service(BallotRepository::class)->cast($poll, $this->lou, Choice::No);
        self::assertNull($this->service(DelegationRepository::class)->delegate($poll, $this->max, $this->ida));

        // Voted, delegated, and received a vote.
        foreach ([$this->lou, $this->max, $this->ida] as $member) {
            $response = $this->setActive($member, false, htmx: true);
            self::assertStringContainsString('hat an einer offenen Abstimmung teilgenommen und kann erst nach ihrem Abschluss inaktiv gesetzt werden.', $response->getHeaderLine('HX-Trigger'));
            self::assertTrue($this->isActive($member));
        }
        self::assertSame(Choice::No, $this->service(BallotRepository::class)->choice($poll, $this->lou));
        self::assertStringContainsString('<b>1</b> von 4 Stimmrechten abgegeben', $this->body($this->request('GET', '/polls/' . $poll)));
    }

    /**
     * An admin may still reopen a poll closed today, and its recount would leave the member's
     * ballot without a voter.
     */
    public function testAMemberWhoTookPartInAPollClosedTodayStaysActiveUntilTomorrow(): void
    {
        $poll = $this->addPoll();
        $this->service(BallotRepository::class)->cast($poll, $this->lou, Choice::Yes);
        $this->service(PollCloser::class)->close($poll, new \DateTimeImmutable('2026-11-03T19:00:00Z'));

        $response = $this->setActive($this->lou, false, htmx: true);
        self::assertStringContainsString('Lou Example hat an einer heute abgeschlossenen Abstimmung teilgenommen und kann erst ab morgen inaktiv gesetzt werden.', $response->getHeaderLine('HX-Trigger'));
        self::assertTrue($this->isActive($this->lou));

        // Past midnight in Berlin, the poll can no longer be reopened.
        $this->app = $this->createApp(overrides: [Clock::class => FixedClock::at('2026-11-03T23:30:00Z')]);
        $this->setActive($this->lou, false);

        self::assertFalse($this->isActive($this->lou));
        // The closed poll keeps the tally of its close.
        self::assertSame(4, $this->service(PollRepository::class)->find($poll)?->tally?->eligible);
    }

    public function testAnInactiveMemberSeesThePollButCannotVoteOrDelegate(): void
    {
        $poll = $this->addPoll();
        $this->setActive($this->lou, false);
        $this->signIn('lou@example.org');

        self::assertStringContainsString('<span class="chip">Inaktiv</span>', $this->body($this->request('GET', '/')));
        $html = $this->body($this->request('GET', '/polls/' . $poll . '?delegate=1'));
        self::assertStringContainsString('<b>Du bist zurzeit inaktiv.</b>', $html);
        self::assertStringNotContainsString('id="choice-yes"', $html);
        self::assertStringNotContainsString('/delegation"', $html);

        $response = $this->post('/polls/' . $poll . '/ballot', ['choice' => 'yes'], htmx: true);
        self::assertStringContainsString('Du bist zurzeit inaktiv und stimmst nicht mit ab.', $response->getHeaderLine('HX-Trigger'));
        $response = $this->post('/polls/' . $poll . '/delegation', ['to' => (string) $this->max], htmx: true);
        self::assertStringContainsString('Du bist zurzeit inaktiv und stimmst nicht mit ab.', $response->getHeaderLine('HX-Trigger'));
        self::assertNull($this->service(BallotRepository::class)->choice($poll, $this->lou));
        self::assertSame([], $this->service(BallotRepository::class)->participantIds($poll));
        self::assertSame([], $this->service(DelegationRepository::class)->forPoll($poll)->all());
    }

    public function testNobodyCanHandTheirVoteToAnInactiveMember(): void
    {
        $poll = $this->addPoll();
        $this->setActive($this->lou, false);
        $this->signIn('max@example.org');

        self::assertStringNotContainsString('Lou Example', $this->body($this->request('GET', '/polls/' . $poll . '?delegate=1')));
        self::assertSame(DelegationRefusal::UnknownTarget, $this->service(DelegationRepository::class)->delegate($poll, $this->max, $this->lou));
    }

    public function testTheRepositoriesRefuseAnInactiveMembersVote(): void
    {
        $poll = $this->addPoll();
        $this->setActive($this->lou, false);

        $this->expectException(MemberInactiveException::class);
        $this->service(BallotRepository::class)->cast($poll, $this->lou, Choice::Yes);
    }

    public function testTheSwitchNeedsAValidValueAndAKnownMember(): void
    {
        self::assertSame(400, $this->post('/admin/members/' . $this->lou . '/active', ['active' => 'no'])->getStatusCode());
        self::assertSame(404, $this->setActive(999, false)->getStatusCode());
        self::assertTrue($this->isActive($this->lou));
    }

    public function testOnlyAdminsCanSwitchIt(): void
    {
        $this->signIn('lou@example.org');

        $this->setActive($this->max, false);

        self::assertTrue($this->isActive($this->max));
    }

    public function testTheSettingsExampleCountsOnlyActiveMembers(): void
    {
        $this->setActive($this->lou, false);

        self::assertStringContainsString('Bei 3 Mitgliedern sind 2 Ja-Stimmen nötig.', $this->body($this->request('GET', '/admin/settings')));
    }
}
