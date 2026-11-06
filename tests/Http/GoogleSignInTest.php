<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Action\GoogleStartAction;
use ShowOfHands\Infrastructure\Auth\GoogleAccount;
use ShowOfHands\Infrastructure\Auth\GoogleSignIn;
use ShowOfHands\Infrastructure\Auth\LeagueGoogleSignIn;

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
        self::assertStringContainsString('Du bist als Kim Example angemeldet.', $this->body($this->request('GET', '/')));
    }

    /**
     * The other tests replace the adapter with a fake. This one sends the visitor off through the
     * real one, which builds the URL locally, and checks what it asks Google for.
     */
    public function testTheRealAdapterAsksForTheAddressWithPkce(): void
    {
        $this->app = $this->createApp(prod: true, overrides: [
            GoogleSignIn::class => new LeagueGoogleSignIn('client-id.example', 'client-secret', 'http://localhost:8080'),
        ]);

        $location = $this->request('GET', '/auth/google')->getHeaderLine('Location');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('client-id.example', $query['client_id'] ?? null);
        self::assertSame('http://localhost:8080/auth/google/callback', $query['redirect_uri'] ?? null);
        self::assertSame('openid email', $query['scope'] ?? null);
        self::assertSame('select_account', $query['prompt'] ?? null);
        self::assertArrayNotHasKey('hd', $query, 'A hosted domain would hide private accounts.');
        self::assertSame('S256', $query['code_challenge_method'] ?? null);

        $stored = $this->session->get(GoogleStartAction::SESSION_KEY);
        self::assertIsArray($stored);
        self::assertIsString($stored['state']);
        self::assertGreaterThanOrEqual(32, strlen($stored['state']));
        self::assertSame($stored['state'], $query['state'] ?? null, 'The callback must present the state the session keeps.');
        self::assertIsString($stored['verifier']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $stored['verifier'], true)), '+/', '-_'), '=');
        self::assertSame($challenge, $query['code_challenge'] ?? null, 'The challenge is the S256 hash of the verifier the session keeps.');
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
