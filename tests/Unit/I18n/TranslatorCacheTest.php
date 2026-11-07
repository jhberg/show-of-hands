<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\I18n\TranslatorFactory;
use ShowOfHands\Tests\TempDirectory;

final class TranslatorCacheTest extends TestCase
{
    use TempDirectory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        mkdir("{$this->dir}/translations");
        mkdir("{$this->dir}/overrides");
        $this->write('translations/messages.de.yaml', "a: 'Hallo {name}'\nb: '{n, plural, one {# Mitglied} other {# Mitglieder}}'\n");
        $this->write('translations/messages.en.yaml', "a: 'Hello {name}'\n");
    }

    private function write(string $path, string $content): void
    {
        file_put_contents("{$this->dir}/{$path}", $content);
    }

    private function translate(string $key, string $locale = 'de'): string
    {
        $translator = TranslatorFactory::create(["{$this->dir}/translations", "{$this->dir}/overrides"], $locale, "{$this->dir}/cache");

        return $translator->trans($key, ['name' => 'Kim', 'n' => 3]);
    }

    public function testReadsTheMessagesFromOneCachedFile(): void
    {
        self::assertSame('Hallo Kim', $this->translate('a'));
        self::assertSame('3 Mitglieder', $this->translate('b'));
        self::assertSame('Hello Kim', $this->translate('a', 'en'));
        self::assertCount(1, glob("{$this->dir}/cache/messages.*.php") ?: []);
        self::assertSame(0o700, fileperms("{$this->dir}/cache") & 0o777);

        // The cache answers even when the YAML is gone for this request's file list.
        $cached = glob("{$this->dir}/cache/messages.*.php") ?: [];
        file_put_contents($cached[0], "<?php return ['de' => ['a' => 'Aus dem Cache']];\n");
        self::assertSame('Aus dem Cache', $this->translate('a'));
    }

    public function testAnAddedOrEditedOverrideTakesEffectAtOnce(): void
    {
        self::assertSame('Hallo Kim', $this->translate('a'));

        $this->write('overrides/messages.de.yaml', "a: 'Servus {name}'\n");
        self::assertSame('Servus Kim', $this->translate('a'));

        $this->write('overrides/messages.de.yaml', "a: 'Moin {name}, schön'\n");
        self::assertSame('Moin Kim, schön', $this->translate('a'));
        self::assertSame('3 Mitglieder', $this->translate('b'));
        // Each change replaces the cached file.
        self::assertCount(1, glob("{$this->dir}/cache/messages.*.php") ?: []);
    }
}
