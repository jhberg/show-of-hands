<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;

final class LocaleSwitchTest extends HttpTestCase
{
    public function testStoresTheLanguageInACookieAndReturns(): void
    {
        $response = $this->post('/locale', ['locale' => 'en', 'return' => '/login']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
        self::assertStringStartsWith('soh_locale=en;', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
    }

    public function testKeepsTheQueryOfTheReturnPath(): void
    {
        self::assertStringContainsString('name="return" value="/login?q=Kim"', $this->body($this->request('GET', '/login?q=Kim')));

        $response = $this->post('/locale', ['locale' => 'en', 'return' => '/login?q=Kim']);

        self::assertSame('/login?q=Kim', $response->getHeaderLine('Location'));
    }

    public function testRejectsAPostWithoutAValidToken(): void
    {
        $this->session->csrfToken();
        $response = $this->request('POST', '/locale', ['_csrf' => 'forged', 'locale' => 'en', 'return' => '/login']);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Formular abgelaufen', $this->body($response));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testIgnoresAnUnsupportedLanguage(): void
    {
        $response = $this->post('/locale', ['locale' => 'fr', 'return' => '/login']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignReturnPaths(): iterable
    {
        yield 'protocol-relative' => ['//evil.example.org/login'];
        yield 'backslash' => ['/\\evil.example.org'];
        yield 'absolute' => ['https://evil.example.org/'];
        yield 'unknown route' => ['/nowhere'];
        yield 'POST-only route' => ['/locale'];
    }

    #[DataProvider('foreignReturnPaths')]
    public function testFallsBackToHomeForAForeignReturnPath(string $path): void
    {
        $response = $this->post('/locale', ['locale' => 'en', 'return' => $path]);

        self::assertSame('/', $response->getHeaderLine('Location'));
    }
}
