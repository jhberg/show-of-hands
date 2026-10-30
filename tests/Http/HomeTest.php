<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;

final class HomeTest extends HttpTestCase
{
    public function testListsOpenPollsByDateThenIdWithTheMembersStatus(): void
    {
        $kim = $this->addMember('Kim Example', ['kim@example.org']);
        $late = $this->addPoll('Late Example', date: '2026-12-01', group: 'A2');
        $this->addPoll('Draft Example', status: PollStatus::Draft);
        $this->addPoll('Closed Example', status: PollStatus::Closed);
        $early = $this->addPoll('Early Example', date: '2026-11-03', group: 'S2');
        $undated = $this->addPoll('Undated Example', kind: PollKind::Exception, question: 'May they?', group: 'B1');
        $sameDay = $this->addPoll('Same Day Example', date: '2026-11-03');
        $this->service(BallotRepository::class)->cast($early, $kim, Choice::No);
        $this->signIn('kim@example.org');

        $html = strstr($this->body($this->request('GET', '/')), 'Abgeschlossen</h2>', true) ?: '';

        self::assertStringContainsString('<i class="dot"></i>4</span>', $html);
        preg_match_all('#<a href="/polls/(\d+)">#', $html, $links);
        self::assertSame([$undated, $early, $sameDay, $late], array_map(intval(...), $links[1]));
        self::assertStringContainsString('<span class="meta">Ausnahme · Bass 1</span>', $html);
        self::assertStringContainsString('<span class="meta">Sopran 2 · 3. Nov.</span>', $html);
        self::assertMatchesRegularExpression('#Early Example</b>.*?</span>\s*</span><span class="chip"><svg.*?</svg>Abgestimmt</span>#s', $html);
        self::assertMatchesRegularExpression('#Late Example</b>.*?</span>\s*</span><span class="chip open">Noch nicht abgestimmt</span>#s', $html);
        self::assertStringNotContainsString('Draft Example', $html);
        self::assertStringNotContainsString('Closed Example', $html);
        self::assertStringNotContainsString('Zurzeit ist keine Abstimmung offen.', $html);
    }
}
