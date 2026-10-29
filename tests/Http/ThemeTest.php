<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

final class ThemeTest extends HttpTestCase
{
    public function testServesTheDerivedTokens(): void
    {
        $response = $this->request('GET', '/theme.css');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/css; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('--accent:#3b6fb6;', $this->body($response));
        self::assertStringContainsString('prefers-color-scheme: dark', $this->body($response));
        self::assertSame('public, max-age=31536000, immutable', $response->getHeaderLine('Cache-Control'));
    }

    public function testBrandColoursChangeTheVersionInThePageLink(): void
    {
        preg_match('#/theme\.css\?v=(\w+)#', $this->body($this->request('GET', '/login')), $default);
        $this->app = $this->createApp(['brand' => ['colors' => ['accent' => '#0f766e']]]);
        preg_match('#/theme\.css\?v=(\w+)#', $this->body($this->request('GET', '/login')), $teal);

        self::assertNotSame($default[1] ?? null, $teal[1] ?? null);
        self::assertStringContainsString('--accent:#0f766e;', $this->body($this->request('GET', '/theme.css')));
    }

    public function testNeedsNoDatabase(): void
    {
        $this->request('GET', '/theme.css');

        self::assertFileDoesNotExist($this->dataDir . '/app.sqlite');
    }
}
