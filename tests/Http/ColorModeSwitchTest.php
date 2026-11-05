<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Domain\ColorMode;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;

final class ColorModeSwitchTest extends HttpTestCase
{
    public function testStoresTheModeInACookieAndReturns(): void
    {
        $response = $this->post('/color-mode', ['mode' => 'dark', 'return' => '/login?q=Kim']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login?q=Kim', $response->getHeaderLine('Location'));
        self::assertStringStartsWith('soh_mode=dark;', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
    }

    public function testRejectsAPostWithoutAValidToken(): void
    {
        $this->session->csrfToken();
        $response = $this->request('POST', '/color-mode', ['_csrf' => 'forged', 'mode' => 'dark', 'return' => '/login']);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testIgnoresAnUnknownMode(): void
    {
        $response = $this->post('/color-mode', ['mode' => 'sepia', 'return' => '/login']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testWithoutAChoiceThePageFollowsTheDevice(): void
    {
        $body = $this->body($this->request('GET', '/login'));

        self::assertStringContainsString('<html lang="de">', $body);
        self::assertStringContainsString('aria-label="Darstellung: Wie System. Ändern"', $body);
        self::assertStringContainsString('name="mode" value="system" aria-pressed="true"', $body);
        self::assertStringContainsString('name="mode" value="dark" aria-pressed="false"', $body);
    }

    public function testTheCookiesModeMarksThePage(): void
    {
        $body = $this->body($this->request('GET', '/login', cookies: ['soh_mode' => 'dark']));

        self::assertStringContainsString('<html lang="de" data-theme="dark">', $body);
        self::assertStringContainsString('aria-label="Darstellung: Dunkel. Ändern"', $body);
        self::assertStringContainsString('name="mode" value="dark" aria-pressed="true"', $body);
        self::assertStringContainsString('name="mode" value="system" aria-pressed="false"', $body);
    }

    public function testAnUnknownCookieFollowsTheDevice(): void
    {
        $body = $this->body($this->request('GET', '/login', cookies: ['soh_mode' => 'sepia']));

        self::assertStringContainsString('<html lang="de">', $body);
    }

    public function testASignedInMemberKeepsTheirChoiceOnEveryDevice(): void
    {
        $id = $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        $this->post('/color-mode', ['mode' => 'light', 'return' => '/']);
        $members = $this->service(MemberRepository::class);
        self::assertSame(ColorMode::Light, $members->find($id)?->colorMode);

        // Another device: no cookie, or one from before sign-in that says otherwise.
        self::assertStringContainsString('<html lang="de" data-theme="light">', $this->body($this->request('GET', '/')));
        self::assertStringContainsString('<html lang="de" data-theme="light">', $this->body($this->request('GET', '/', cookies: ['soh_mode' => 'dark'])));
    }

    public function testAMemberWhoNeverChoseFollowsTheCookie(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        self::assertStringContainsString('<html lang="de" data-theme="dark">', $this->body($this->request('GET', '/', cookies: ['soh_mode' => 'dark'])));
    }

    public function testChoosingTheSystemAgainRemovesTheMark(): void
    {
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->signIn('kim@example.org');

        $this->post('/color-mode', ['mode' => 'dark', 'return' => '/']);
        $this->post('/color-mode', ['mode' => 'system', 'return' => '/']);

        self::assertStringContainsString('<html lang="de">', $this->body($this->request('GET', '/', cookies: ['soh_mode' => 'dark'])));
    }

    public function testTheSwitchSpeaksTheMembersLanguage(): void
    {
        $body = $this->body($this->request('GET', '/login', cookies: ['soh_locale' => 'en', 'soh_mode' => 'light']));

        self::assertStringContainsString('aria-label="Appearance: Light. Change"', $body);
        self::assertStringContainsString('Match system</button>', $body);
    }
}
