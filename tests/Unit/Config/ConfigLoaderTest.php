<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\Config\ConfigException;
use ShowOfHands\Infrastructure\Config\ConfigLoader;

final class ConfigLoaderTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function valid(): array
    {
        return [
            'app' => [
                'url' => 'https://vote.example.org/',
                'secret' => str_repeat('0f', 32),
                'timezone' => 'Europe/Berlin',
            ],
            'google' => ['client_id' => 'id.apps.googleusercontent.com', 'client_secret' => 'secret'],
            'brand' => ['name' => 'Example Choir', 'wordmark_accent' => '*'],
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function errors(array $data): array
    {
        try {
            ConfigLoader::fromArray($data);
        } catch (ConfigException $e) {
            return $e->errors;
        }
        self::fail('The config was accepted.');
    }

    public function testAppliesDefaults(): void
    {
        $config = ConfigLoader::fromArray(self::valid());

        self::assertSame('https://vote.example.org', $config->appUrl);
        self::assertSame('prod', $config->env);
        self::assertSame('de', $config->defaultLocale);
        self::assertSame(30, $config->sessionLifetimeDays);
        self::assertSame('Example Choir', $config->brand->wordmark);
        self::assertSame('#3b6fb6', $config->brand->accent);
        self::assertTrue($config->isHttps());
    }

    public function testReportsEveryProblemAtOnce(): void
    {
        $errors = self::errors(['app' => ['url' => 'vote.example.org', 'secret' => 'short'], 'brand' => ['colors' => ['accent' => 'blue']]]);

        self::assertContains("app.url must be an absolute URL such as 'https://vote.example.org'.", $errors);
        self::assertNotContains('app.url must start with https:// in production.', $errors);
        self::assertContains('app.timezone is required.', $errors);
        self::assertContains('google.client_id is required.', $errors);
        self::assertContains('brand.name is required.', $errors);
        self::assertContains("brand.colors.accent must be a hex colour such as '#3b6fb6'.", $errors);
        self::assertCount(1, array_filter($errors, static fn(string $e): bool => str_starts_with($e, 'app.secret must be at least 64 hex')));
    }

    public function testReportsAnEmptyUrlOnce(): void
    {
        $data = self::valid();
        $data['app']['url'] = '';

        self::assertSame(['app.url must not be empty.'], self::errors($data));
    }

    public function testNamesUnknownKeys(): void
    {
        $data = self::valid();
        $data['brand']['colours'] = [];
        $data['mail'] = [];

        $errors = self::errors($data);

        self::assertContains("Unknown key 'brand.colours'.", $errors);
        self::assertCount(1, array_filter($errors, static fn(string $e): bool => str_starts_with($e, "Unknown section 'mail'.")));
    }

    public function testRejectsDottedKeysAtTheTop(): void
    {
        $data = self::valid();
        $data['brand.colors'] = ['accent' => '#0f766e'];

        self::assertCount(1, array_filter(self::errors($data), static fn(string $e): bool => str_starts_with($e, "Unknown section 'brand.colors'.")));
    }

    public function testRefusesTheDevLoginOutsideLocalhost(): void
    {
        $data = self::valid();
        $data['app']['env'] = 'dev';

        self::assertContains("app.env 'dev' enables the dev login, so app.url must point at localhost or 127.0.0.1.", self::errors($data));
    }

    public function testDevNeedsNoGoogleClient(): void
    {
        $data = self::valid();
        $data['app']['url'] = 'http://localhost:8080';
        $data['app']['env'] = 'dev';
        unset($data['google']);

        self::assertTrue(ConfigLoader::fromArray($data)->isDev());
    }

    public function testRequiresHttpsInProduction(): void
    {
        $data = self::valid();
        $data['app']['url'] = 'http://vote.example.org';

        self::assertContains('app.url must start with https:// in production.', self::errors($data));
    }

    public function testRejectsAPathInTheUrl(): void
    {
        $data = self::valid();
        $data['app']['url'] = 'https://example.org/vote';

        self::assertContains('app.url must not have a path. The app runs at the root of its own (sub)domain.', self::errors($data));
    }

    public function testValidatesFontFiles(): void
    {
        $data = self::valid();
        $data['brand']['font'] = ['files' => ['../../config.php', ['file' => 'Inter.woff2', 'weight' => 950]]];

        $errors = self::errors($data);

        self::assertContains('brand.font.files[0].file must be a .woff2 or .woff file name in public/brand/fonts/.', $errors);
        self::assertContains('brand.font.files[1].weight must be a number between 100 and 900.', $errors);
    }

    public function testExplainsAMissingFile(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Copy config/config.example.php to config/config.php');

        ConfigLoader::fromFile('/nonexistent/config.php');
    }

    public function testTheExampleFileOnlyLacksSecrets(): void
    {
        try {
            ConfigLoader::fromFile(dirname(__DIR__, 3) . '/config/config.example.php');
            self::fail('The example was accepted without secrets.');
        } catch (ConfigException $e) {
            self::assertSame(['app.secret must not be empty.', 'google.client_id must not be empty.', 'google.client_secret must not be empty.'], $e->errors);
        }
    }

    public function testSplitsTheWordmarkIntoAccentRuns(): void
    {
        $data = self::valid();
        $data['brand']['wordmark'] = 'S*NG';

        self::assertSame(
            [['text' => 'S', 'accent' => false], ['text' => '*', 'accent' => true], ['text' => 'NG', 'accent' => false]],
            ConfigLoader::fromArray($data)->brand->wordmarkRuns(),
        );
    }
}
