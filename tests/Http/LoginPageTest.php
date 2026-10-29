<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\Middleware\SecurityHeadersMiddleware;

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
        self::assertStringContainsString('href="/auth/google"', $html);
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

    public function testSendsSecurityHeaders(): void
    {
        $response = $this->request('GET', '/login');

        self::assertSame(SecurityHeadersMiddleware::CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function testSendsHstsWhenServedOverHttps(): void
    {
        $this->app = $this->createApp(['app' => ['url' => 'https://vote.example.org', 'env' => 'prod'], 'google' => ['client_id' => 'id', 'client_secret' => 'secret']]);

        self::assertSame('max-age=31536000', $this->request('GET', '/login')->getHeaderLine('Strict-Transport-Security'));
    }

    public function testPagesHaveNoInlineStylesOrScripts(): void
    {
        $html = $this->body($this->request('GET', '/login'));

        self::assertDoesNotMatchRegularExpression('/\sstyle=/', $html);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/', $html);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $html);
    }

    public function testHomeSendsVisitorsToTheLoginPage(): void
    {
        $response = $this->request('GET', '/');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }
}
