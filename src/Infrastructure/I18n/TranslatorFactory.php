<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\I18n;

use ShowOfHands\Infrastructure\Storage\DataDirectory;
use Symfony\Component\Translation\Loader\ArrayLoader;
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
     * @param string $cacheDir where the parsed messages go, so a request reads PHP instead of YAML
     */
    public static function create(array $dirs, string $locale, string $cacheDir): Translator
    {
        $files = [];
        foreach (Locales::SUPPORTED as $supported) {
            foreach ($dirs as $dir) {
                $file = self::file($dir, $supported);
                if (is_file($file)) {
                    $files[] = [$file, $supported];
                }
            }
        }
        $translator = new Translator($locale);
        $translator->addLoader('array', new ArrayLoader());
        foreach (self::cached($files, $cacheDir) as $supported => $messages) {
            $translator->addResource('array', $messages, $supported, self::DOMAIN . MessageCatalogue::INTL_DOMAIN_SUFFIX);
        }

        return $translator;
    }

    /**
     * Every locale's messages, merged in the order of the files, from a PHP file in the cache. Its
     * name covers each file's path, time, and size, so an edited or added override takes effect at once.
     *
     * @param list<array{string, string}> $files YAML files with their locale
     *
     * @return array<string, array<string, string>> messages by locale
     */
    private static function cached(array $files, string $cacheDir): array
    {
        $stamp = array_map(static fn(array $entry): array => [$entry[0], @filemtime($entry[0]), @filesize($entry[0])], $files);
        $cache = $cacheDir . '/messages.' . substr(sha1(serialize($stamp)), 0, 16) . '.php';
        $messages = is_file($cache) ? include $cache : null;
        if (is_array($messages)) {
            /** @var array<string, array<string, string>> $messages */
            return $messages;
        }

        $messages = [];
        $loader = new YamlFileLoader();
        foreach ($files as [$file, $locale]) {
            /** @var array<string, string> $loaded */
            $loaded = $loader->load($file, $locale, self::DOMAIN)->all(self::DOMAIN);
            $messages[$locale] = array_replace($messages[$locale] ?? [], $loaded);
        }
        // A request that cannot write the cache still has its messages, and the next one tries again.
        try {
            DataDirectory::create($cacheDir);
        } catch (\RuntimeException) {
            return $messages;
        }
        foreach (glob($cacheDir . '/messages.*.php') ?: [] as $stale) {
            @unlink($stale);
        }
        // Written aside and renamed, so a parallel request never includes half a file.
        $temporary = @tempnam($cacheDir, 'messages');
        if ($temporary === false) {
            return $messages;
        }
        if (@file_put_contents($temporary, '<?php return ' . var_export($messages, true) . ";\n") === false || !@rename($temporary, $cache)) {
            @unlink($temporary);
        }

        return $messages;
    }
}
