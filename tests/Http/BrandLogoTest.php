<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Infrastructure\Config\BrandAssets;

final class BrandLogoTest extends HttpTestCase
{
    /**
     * Rebuilds the app with a public directory of its own that holds the given brand files.
     *
     * @param list<string> $files names under public/brand/
     */
    private function withBrandFiles(array $files): void
    {
        $public = $this->dataDir . '/public';
        mkdir($public . '/brand', 0o777, true);
        foreach ($files as $file) {
            file_put_contents($public . '/brand/' . $file, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        }
        $this->app = $this->createApp(overrides: [BrandAssets::class => new BrandAssets($public)]);
    }

    public function testTheLoginPageShowsTheDarkLogoInDarkMode(): void
    {
        $this->withBrandFiles(['logo.svg', 'logo-dark.png']);

        $body = $this->body($this->request('GET', '/login'));

        self::assertMatchesRegularExpression('#<picture class="logo-pic"><source media="\(prefers-color-scheme: dark\)" srcset="/brand/logo-dark\.png\?v=\d+"><img class="logo" src="/brand/logo\.svg\?v=\d+" alt="Example Choir"></picture>#', $body);
    }

    public function testWithoutADarkLogoTheLoginPageShowsTheLogoAlone(): void
    {
        $this->withBrandFiles(['logo.svg']);

        $body = $this->body($this->request('GET', '/login'));

        self::assertMatchesRegularExpression('#<img class="logo" src="/brand/logo\.svg\?v=\d+" alt="Example Choir">#', $body);
        self::assertStringNotContainsString('<picture', $body);
        self::assertStringNotContainsString('<source', $body);
    }

    public function testADarkLogoWithoutALightOneLeavesTheWordmark(): void
    {
        $this->withBrandFiles(['logo-dark.svg']);

        $body = $this->body($this->request('GET', '/login'));

        self::assertStringContainsString('class="logo-wm"', $body);
        self::assertStringNotContainsString('logo-dark', $body);
    }

    public function testTheHeaderShowsTheDarkHeaderLogoInDarkMode(): void
    {
        $this->withBrandFiles(['header-logo.png', 'header-logo-dark.svg']);
        $this->addMember('Robin Example', ['robin@example.org']);
        $this->signIn('robin@example.org');

        $body = $this->body($this->request('GET', '/'));

        self::assertMatchesRegularExpression('#<picture class="logo-pic"><source media="\(prefers-color-scheme: dark\)" srcset="/brand/header-logo-dark\.svg\?v=\d+"><img class="hlogo" src="/brand/header-logo\.png\?v=\d+" alt="Example Choir"></picture>#', $body);
    }

    public function testWithoutADarkHeaderLogoTheHeaderShowsTheLogoAlone(): void
    {
        $this->withBrandFiles(['header-logo.png']);
        $this->addMember('Robin Example', ['robin@example.org']);
        $this->signIn('robin@example.org');

        $body = $this->body($this->request('GET', '/'));

        self::assertMatchesRegularExpression('#<img class="hlogo" src="/brand/header-logo\.png\?v=\d+" alt="Example Choir">#', $body);
        self::assertStringNotContainsString('<source', $body);
    }
}
