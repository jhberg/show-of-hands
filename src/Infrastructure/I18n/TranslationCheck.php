<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\I18n;

use Symfony\Component\Yaml\Yaml;

/**
 * Finds translation problems: keys that exist in one language only, keys the code
 * uses but no file defines, and override keys that match no shipped key.
 */
final class TranslationCheck
{
    /**
     * Matches 'key'|trans in Twig, and ->trans('key' and ->toast('key' in PHP.
     */
    private const USAGE_PATTERNS = [
        '/[\'"]([a-z0-9_]+(?:\.[a-z0-9_]+)+)[\'"]\s*\|\s*trans\b/',
        '/->(?:trans|toast)\(\s*[\'"]([a-z0-9_]+(?:\.[a-z0-9_]+)+)[\'"]/',
    ];

    /**
     * @param list<string> $sourceDirs templates and PHP sources to scan for used keys
     */
    public function __construct(
        private readonly string $translationsDir,
        private readonly array $sourceDirs,
        private readonly ?string $overridesDir = null,
    ) {}

    /**
     * @return list<string> one line per problem, empty when everything matches
     */
    public function problems(): array
    {
        $problems = [];
        $keys = [];
        foreach (Locales::SUPPORTED as $locale) {
            $keys[$locale] = $this->keys($this->translationsDir, $locale);
            if ($keys[$locale] === null) {
                $problems[] = "translations/messages.{$locale}.yaml is missing.";
                $keys[$locale] = [];
            }
        }

        foreach ($keys as $locale => $own) {
            foreach ($keys as $other => $theirs) {
                foreach (array_diff($own, $theirs) as $key) {
                    $problems[] = "'{$key}' exists in {$locale} but not in {$other}.";
                }
            }
        }

        $known = array_unique(array_merge(...array_values($keys)));
        foreach ($this->usedKeys() as $key => $file) {
            if (!in_array($key, $known, true)) {
                $problems[] = "'{$key}' is used in {$file} but defined in no translation file.";
            }
        }

        if ($this->overridesDir !== null) {
            foreach (Locales::SUPPORTED as $locale) {
                foreach (array_diff($this->keys($this->overridesDir, $locale) ?? [], $known) as $key) {
                    $problems[] = "config/translations/messages.{$locale}.yaml overrides '{$key}', which the app does not use.";
                }
            }
        }

        return $problems;
    }

    /**
     * @return list<string>|null null when the file does not exist
     */
    private function keys(string $dir, string $locale): ?array
    {
        $file = TranslatorFactory::file($dir, $locale);
        if (!is_file($file)) {
            return null;
        }
        $data = Yaml::parseFile($file);

        return is_array($data) ? self::flatten($data) : [];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $path = $prefix . $key;
            if (is_array($value)) {
                array_push($keys, ...self::flatten($value, $path . '.'));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, string> key => first file that uses it
     */
    private function usedKeys(): array
    {
        $used = [];
        foreach ($this->sourceDirs as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['twig', 'php'], true)) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                foreach (self::USAGE_PATTERNS as $pattern) {
                    preg_match_all($pattern, $source, $matches);
                    foreach ($matches[1] as $key) {
                        $used[$key] ??= $file->getFilename();
                    }
                }
            }
        }

        return $used;
    }
}
