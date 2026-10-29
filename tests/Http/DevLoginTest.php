<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\DevLoginGuard;

final class DevLoginTest extends HttpTestCase
{
    public function testListsEveryMemberWithEachAddress(): void
    {
        $this->addMember('Kim Example', ['kim@example.org', 'kim@example.net'], admin: true);

        $html = $this->body($this->request('GET', '/dev-login?return=/polls/3'));

        self::assertStringContainsString('Konto auswählen', $html);
        self::assertStringContainsString('value="kim@example.org"', $html);
        self::assertStringContainsString('value="kim@example.net"', $html);
        self::assertStringContainsString('name="return" value="/polls/3"', $html);
    }

    public function testSignsInAndReturns(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $before = $this->session->memoryId();
        $token = $this->session->csrfToken();

        $response = $this->signIn('Kim@Example.org', '/polls/3');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/polls/3', $response->getHeaderLine('Location'));
        self::assertNotSame($before, $this->session->memoryId(), 'Signing in gives the session a new id.');
        self::assertNotSame($token, $this->session->csrfToken(), 'and a new CSRF token.');
        self::assertStringContainsString('Angemeldet als Kim Example.', $this->body($this->request('GET', '/')));
        self::assertStringNotContainsString('Angemeldet als', $this->body($this->request('GET', '/')), 'The toast shows once.');
    }

    public function testNamesTheSecondAddressInTheToast(): void
    {
        $this->addMember('Kim Example', ['kim@example.org', 'kim@example.net']);

        $this->signIn('kim@example.net');

        self::assertStringContainsString('Angemeldet als Kim Example über kim@example.net.', $this->body($this->request('GET', '/')));
    }

    public function testShowsAnUnknownAddressOnTheLoginPage(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);

        $response = $this->signIn('stranger@example.net', '/polls/3');

        self::assertSame('/login?return=%2Fpolls%2F3', $response->getHeaderLine('Location'));
        $html = $this->body($this->request('GET', '/login?return=/polls/3'));
        self::assertStringContainsString('Diese Adresse ist nicht als Mitglied hinterlegt.', $html);
        self::assertStringContainsString('<span class="mono">stranger@example.net</span>', $html);
        self::assertStringContainsString('Mit einem anderen Konto anmelden', $html);
        self::assertStringContainsString('Nach der Anmeldung geht es direkt zur Abstimmung.', $html);
        self::assertStringNotContainsString('stranger@example.net', $this->body($this->request('GET', '/login')), 'The error shows once.');
        self::assertSame(303, $this->request('GET', '/')->getStatusCode(), 'Nobody is signed in.');
    }

    public function testAFailedSwitchSignsTheOldMemberOut(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        $this->signIn('stranger@example.net');

        self::assertStringContainsString('stranger@example.net', $this->body($this->request('GET', '/login')));
        self::assertSame('/login', $this->request('GET', '/')->getHeaderLine('Location'));
    }

    public function testDoesNotExistInProduction(): void
    {
        $this->app = $this->createApp(prod: true);

        self::assertSame(404, $this->request('GET', '/dev-login')->getStatusCode());
    }

    public function testRefusesAHostThatIsNotLoopback(): void
    {
        self::assertSame(404, $this->request('GET', 'http://vote.example.org/dev-login')->getStatusCode());
    }

    public function testRefusesAPeerThatIsNotLoopback(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);

        self::assertSame(404, $this->request('GET', '/dev-login', server: ['REMOTE_ADDR' => '203.0.113.7'])->getStatusCode());
        self::assertSame(404, $this->request('GET', '/dev-login', server: ['REMOTE_ADDR' => '172.21.0.1'])->getStatusCode());
    }

    public function testAcceptsAConfiguredPeerRange(): void
    {
        $this->app = $this->createApp(overrides: [DevLoginGuard::class => new DevLoginGuard(true, ['172.16.0.0/12'])]);

        self::assertSame(200, $this->request('GET', '/dev-login', server: ['REMOTE_ADDR' => '172.21.0.1'])->getStatusCode());
        self::assertSame(404, $this->request('GET', '/dev-login', server: ['REMOTE_ADDR' => '172.32.0.1'])->getStatusCode());
    }

    public function testSignOutForgetsTheMember(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');
        $before = $this->session->memoryId();

        $response = $this->post('/logout');

        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertNotSame($before, $this->session->memoryId());
        self::assertSame('/login', $this->request('GET', '/')->getHeaderLine('Location'));
    }

    public function testSignsOutARemovedMemberAtOnce(): void
    {
        $id = $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');
        self::assertSame(200, $this->request('GET', '/')->getStatusCode());

        $this->service(\PDO::class)->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);

        self::assertSame('/login', $this->request('GET', '/')->getHeaderLine('Location'));
    }
}
