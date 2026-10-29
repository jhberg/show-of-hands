<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\ReturnPath;

final class ErrorPageTest extends HttpTestCase
{
    public function testRendersNotFoundInTheAppStyle(): void
    {
        $response = $this->request('GET', '/does-not-exist', cookies: [LocaleMiddleware::COOKIE => 'en']);

        self::assertSame(404, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('Page not found', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('value="en" lang="en" aria-label="English" aria-current="true"', $html);
        self::assertStringContainsString('class="top"', $html);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testTreatsAWrongMethodAsNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/locale')->getStatusCode());
    }

    public function testRendersAndLogsAServerErrorWithoutRequestData(): void
    {
        $this->app = $this->createApp(prod: true, overrides: [
            ReturnPath::class => static fn(): never => throw new \RuntimeException('Broken on purpose.'),
        ]);

        $response = $this->post('/locale', ['locale' => 'en', 'return' => '/secret-value']);

        self::assertSame(500, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('Etwas ist schiefgelaufen', $html);
        self::assertStringNotContainsString('class="details"', $html);
        self::assertStringNotContainsString('Broken on purpose', $html);
        $log = (string) file_get_contents($this->dataDir . '/logs/app.log');
        self::assertStringContainsString('RuntimeException: Broken on purpose.', $log);
        self::assertStringNotContainsString('secret-value', $log);
    }

    public function testShowsErrorDetailsInDevelopment(): void
    {
        $this->app = $this->createApp(overrides: [
            ReturnPath::class => static fn(): never => throw new \RuntimeException('Broken on purpose.'),
        ]);

        $html = $this->body($this->post('/locale', ['locale' => 'en']));

        self::assertStringContainsString('class="details"', $html);
        self::assertStringContainsString('Broken on purpose.', $html);
    }
}
