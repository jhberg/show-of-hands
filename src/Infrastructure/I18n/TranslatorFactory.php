<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\I18n;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;

/**
 * Builds the translator from translations/ and the instance's overrides in config/translations/.
 *
 * Messages use ICU MessageFormat. An override file needs only the keys it changes,
 * because it is loaded after the shipped file and wins key by key.
 */
final class TranslatorFactory
{
    public const DOMAIN = 'messages';

    public static function file(string $dir, string $locale): string
    {
        return sprintf('%s/%s.%s.yaml', $dir, self::DOMAIN, $locale);
    }

    /**
     * @param list<string> $dirs searched in order, later directories override earlier ones
     */
    public static function create(array $dirs, string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (Locales::SUPPORTED as $supported) {
            foreach ($dirs as $dir) {
                $file = self::file($dir, $supported);
                if (is_file($file)) {
                    $translator->addResource('yaml', $file, $supported, self::DOMAIN . MessageCatalogue::INTL_DOMAIN_SUFFIX);
                }
            }
        }

        return $translator;
    }
}
