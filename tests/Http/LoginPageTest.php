<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Middleware\LocaleMiddleware;

final class LoginPageTest extends HttpTestCase
{
    public function testRendersInTheDefaultLocale(): void
    {
        $response = $this->request('GET', '/login');

        self::assertSame(200, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('<html lang="de">', $html);
        self::assertStringContainsString('Mit Google anmelden', $html);
        self::assertStringContainsString('dem Example Choir-Konto', $html);
        self::assertStringContainsString('href="/dev-login"', $html, 'Without a Google client, development signs in through the dev login.');
    }

    public function testLinksTheGoogleFlowWhenAClientIsConfigured(): void
    {
        $this->app = $this->createApp(prod: true);

        $html = $this->body($this->request('GET', '/login'));

        self::assertStringContainsString('href="/auth/google"', $html);
        self::assertStringNotContainsString('/dev-login', $html);
    }

    public function testRendersInTheLanguageFromTheCookie(): void
    {
        $html = $this->body($this->request('GET', '/login', cookies: [LocaleMiddleware::COOKIE => 'en']));

        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('Sign in with Google', $html);
    }

    public function testIgnoresAnUnknownLanguageCookie(): void
    {
        $html = $this->body($this->request('GET', '/login', cookies: [LocaleMiddleware::COOKIE => 'fr']));

        self::assertStringContainsString('<html lang="de">', $html);
    }

    public function testFollowsTheConfiguredDefaultLocale(): void
    {
        $this->app = $this->createApp(['locale' => ['default' => 'en']]);

        self::assertStringContainsString('<html lang="en">', $this->body($this->request('GET', '/login')));
    }

    public function testHomeSendsVisitorsToTheLoginPage(): void
    {
        $response = $this->request('GET', '/');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }
}
