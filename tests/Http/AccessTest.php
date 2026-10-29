<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\Middleware\RequireMember;
use ShowOfHands\Http\Middleware\RequireRole;

final class AccessTest extends HttpTestCase
{
    public function testADeepLinkReturnsToThePollAfterSignIn(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);

        $response = $this->request('GET', '/polls/3');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?return=%2Fpolls%2F3', $response->getHeaderLine('Location'));
        self::assertStringContainsString('Nach der Anmeldung geht es direkt zur Abstimmung.', $this->body($this->request('GET', '/login?return=/polls/3')));

        self::assertSame('/polls/3', $this->signIn('kim@example.org', '/polls/3')->getHeaderLine('Location'));
        $poll = $this->request('GET', '/polls/3');
        self::assertSame(404, $poll->getStatusCode());
        self::assertStringContainsString('Diese Abstimmung gibt es nicht mehr oder sie ist noch nicht freigeschaltet.', $this->body($poll));
        self::assertStringContainsString('Alle Abstimmungen', $this->body($poll));
    }

    public function testTheLoginPageGivesNoReturnHintForTheHomePage(): void
    {
        self::assertStringNotContainsString('direkt zur Abstimmung', $this->body($this->request('GET', '/login')));
        self::assertStringNotContainsString('direkt zur Abstimmung', $this->body($this->request('GET', '/login?return=//evil.example.org')));
    }

    public function testTheLoginPageSendsASignedInMemberOn(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        self::assertSame('/polls/3', $this->request('GET', '/login?return=/polls/3')->getHeaderLine('Location'));
    }

    public function testAnExpiredSessionRedirectsHtmxThroughTheWholePage(): void
    {
        $response = $this->request('GET', '/polls/3', headers: ['HX-Request' => 'true', 'HX-Current-URL' => 'http://localhost:8080/polls/3?delegate=1']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/login?return=%2Fpolls%2F3%3Fdelegate%3D1', $response->getHeaderLine('HX-Redirect'));
        self::assertFalse($response->hasHeader('Location'));
    }

    public function testHtmxFromAnotherOriginReturnsHome(): void
    {
        $response = $this->request('GET', '/polls/3', headers: ['HX-Request' => 'true', 'HX-Current-URL' => 'https://evil.example.org/polls/3']);

        self::assertSame('/login', $response->getHeaderLine('HX-Redirect'));
    }

    public function testHomeGreetsTheMemberAndShowsBothEmptyStates(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        $html = $this->body($this->request('GET', '/'));

        self::assertStringContainsString('Hallo Kim', $html);
        self::assertStringContainsString('Zurzeit ist keine Abstimmung offen.', $html);
        self::assertStringContainsString('Ergebnisse werden 14 Tage nach dem Abschluss automatisch gelöscht.', $html);
        self::assertStringContainsString('<span class="avatar" aria-hidden="true">KE</span>', $html);
        self::assertStringContainsString('<span class="uname">Kim Example</span>', $html);
        self::assertStringContainsString('Abmelden', $html);
        self::assertStringContainsString('aria-current="page">Abstimmen</a>', $html);
        self::assertStringNotContainsString('/admin/polls', $html, 'Members see no admin link.');
    }

    public function testSignedInPagesAreNeverCached(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        self::assertSame('no-store', $this->request('GET', '/')->getHeaderLine('Cache-Control'));
    }

    public function testAdminPagesSendMembersHome(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        self::assertSame('/', $this->request('GET', '/admin/polls')->getHeaderLine('Location'));
        self::assertSame('/', $this->request('GET', '/admin/polls', headers: ['HX-Request' => 'true'])->getHeaderLine('HX-Redirect'));
    }

    public function testAdminsReachTheAdminArea(): void
    {
        $this->addMember('Kim Example', ['kim@example.org'], admin: true);
        $this->signIn('kim@example.org');

        $home = $this->body($this->request('GET', '/'));
        self::assertStringContainsString('<a href="/admin/polls">Verwaltung</a>', $home);

        $admin = $this->request('GET', '/admin/polls');
        self::assertSame(200, $admin->getStatusCode());
        self::assertStringContainsString('class="on" aria-current="page">Verwaltung</a>', $this->body($admin));
    }

    public function testBoardRoutesRefuseOtherMembers(): void
    {
        $responses = $this->app->getResponseFactory();
        $this->app->get('/board-only', fn($request, ResponseInterface $response): ResponseInterface => $response)
            ->add(RequireRole::board($responses))
            ->add(new RequireMember($responses));
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->addMember('Sam Board', ['sam@example.org'], board: true);

        self::assertSame('/login?return=%2Fboard-only', $this->request('GET', '/board-only')->getHeaderLine('Location'));
        $this->signIn('kim@example.org');
        self::assertSame(403, $this->request('GET', '/board-only')->getStatusCode());
        $this->signIn('sam@example.org');
        self::assertSame(200, $this->request('GET', '/board-only')->getStatusCode());
    }

    public function testTheMembersSavedLanguageWins(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        $this->post('/locale', ['locale' => 'en', 'return' => '/']);

        $html = $this->body($this->request('GET', '/', cookies: [LocaleMiddleware::COOKIE => 'de']));
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Hello Kim', $html);
    }
}
