<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\I18n\TranslationCheck;
use ShowOfHands\Infrastructure\I18n\TranslatorFactory;
use ShowOfHands\Tests\TempDirectory;

final class TranslationCheckTest extends TestCase
{
    use TempDirectory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        foreach (['translations', 'templates', 'overrides'] as $sub) {
            mkdir("{$this->dir}/{$sub}");
        }
    }

    private function write(string $path, string $content): void
    {
        file_put_contents("{$this->dir}/{$path}", $content);
    }

    public function testFindsKeysMissingInOneLanguageAndUnknownKeys(): void
    {
        $this->write('translations/messages.de.yaml', "login:\n  title: Anmelden\n  only_de: Nur hier\n");
        $this->write('translations/messages.en.yaml', "login:\n  title: Sign in\n");
        $this->write('templates/page.html.twig', "{{ 'login.title'|trans }} {{ 'login.typo'|trans }}");
        $this->write('overrides/messages.de.yaml', "login:\n  titel: Hallo\n");

        $problems = (new TranslationCheck("{$this->dir}/translations", ["{$this->dir}/templates"], "{$this->dir}/overrides"))->problems();

        self::assertSame([
            "'login.only_de' exists in de but not in en.",
            "'login.typo' is used in page.html.twig but defined in no translation file.",
            "config/translations/messages.de.yaml overrides 'login.titel', which the app does not use.",
        ], $problems);
    }

    public function testOverridesWinKeyByKeyAndFormatIcu(): void
    {
        $this->write('translations/messages.de.yaml', "a: 'Hallo {name}'\nb: '{n, plural, one {# Mitglied} other {# Mitglieder}}'\n");
        $this->write('overrides/messages.de.yaml', "a: 'Servus {name}'\n");

        $translator = TranslatorFactory::create(["{$this->dir}/translations", "{$this->dir}/overrides"], 'de');

        self::assertSame('Servus Kim', $translator->trans('a', ['name' => 'Kim']));
        self::assertSame('3 Mitglieder', $translator->trans('b', ['n' => 3]));
        self::assertSame('1 Mitglied', $translator->trans('b', ['n' => 1]));
    }
}
