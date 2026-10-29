<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Infrastructure\Auth\GoogleAccount;

final class GoogleSignInTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp(prod: true);
        $this->addMember('Kim Example', ['kim@example.org', 'kim@example.net']);
    }

    public function testSendsTheVisitorToGoogleAndBack(): void
    {
        $html = $this->body($this->request('GET', '/login?return=/polls/3'));
        self::assertStringContainsString('href="/auth/google?return=%2Fpolls%2F3"', $html);

        $start = $this->request('GET', '/auth/google?return=/polls/3');
        self::assertSame(303, $start->getStatusCode());
        self::assertStringStartsWith('https://accounts.google.test/auth', $start->getHeaderLine('Location'));

        $this->google->account = new GoogleAccount('kim@example.org', true);
        $callback = $this->request('GET', '/auth/google/callback?state=' . FakeGoogleSignIn::STATE . '&code=abc');

        self::assertSame('/polls/3', $callback->getHeaderLine('Location'));
        self::assertSame([['abc', FakeGoogleSignIn::VERIFIER]], $this->google->finished);
        self::assertStringContainsString('Angemeldet als Kim Example.', $this->body($this->request('GET', '/')));
    }

    public function testRejectsAForeignState(): void
    {
        $this->request('GET', '/auth/google');
        $this->google->account = new GoogleAccount('kim@example.org', true);

        $response = $this->request('GET', '/auth/google/callback?state=forged&code=abc');

        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertSame([], $this->google->finished, 'A forged state never reaches Google.');
        self::assertStringContainsString('Die Anmeldung hat nicht geklappt.', $this->body($this->request('GET', '/login')));
    }

    public function testAcceptsACallbackOnlyOnce(): void
    {
        $this->request('GET', '/auth/google');
        $this->google->account = new GoogleAccount('kim@example.org', true);
        $this->request('GET', '/auth/google/callback?state=' . FakeGoogleSignIn::STATE . '&code=abc');
        $this->post('/logout');

        $replay = $this->request('GET', '/auth/google/callback?state=' . FakeGoogleSignIn::STATE . '&code=abc');

        self::assertSame('/login', $replay->getHeaderLine('Location'));
        self::assertCount(1, $this->google->finished);
    }

    public function testTreatsAnUnverifiedAddressAsUnknown(): void
    {
        $this->request('GET', '/auth/google');
        $this->google->account = new GoogleAccount('kim@example.org', false);

        $this->request('GET', '/auth/google/callback?state=' . FakeGoogleSignIn::STATE . '&code=abc');

        self::assertStringContainsString('Diese Adresse ist nicht als Mitglied hinterlegt.', $this->body($this->request('GET', '/login')));
    }

    public function testReturnsQuietlyWhenTheVisitorCancelsAtGoogle(): void
    {
        $this->request('GET', '/auth/google?return=/polls/3');

        $response = $this->request('GET', '/auth/google/callback?state=' . FakeGoogleSignIn::STATE . '&error=access_denied');

        self::assertSame('/login?return=%2Fpolls%2F3', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('Die Anmeldung hat nicht geklappt.', $this->body($this->request('GET', '/login')));
    }

    public function testLogsAFailedCodeExchangeWithoutTheCode(): void
    {
        $this->request('GET', '/auth/google');

        $response = $this->request('GET', '/auth/google/callback?state=' . FakeGoogleSignIn::STATE . '&code=secret-code');

        self::assertSame('/login', $response->getHeaderLine('Location'));
        $log = (string) file_get_contents($this->dataDir . '/logs/app.log');
        self::assertStringContainsString('GoogleSignInException: Google refused the code.', $log);
        self::assertStringNotContainsString('secret-code', $log);
    }

    public function testDoesNotOfferTheFlowWithoutAClient(): void
    {
        $this->app = $this->createApp();

        self::assertSame(404, $this->request('GET', '/auth/google')->getStatusCode());
    }
}
