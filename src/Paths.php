<?php

declare(strict_types=1);

namespace ShowOfHands;

/**
 * Where the app finds its code, instance files, and runtime data.
 */
final readonly class Paths
{
    /**
     * The release's version from the VERSION file that bin/build-release writes, or null in a git
     * checkout. Maintenance deletes the caches of every other version, so a name like ".." never gets through.
     */
    public ?string $release;

    public function __construct(
        public string $root,
        public ?string $dataOverride = null,
        public ?string $configOverride = null,
    ) {
        $release = trim((string) @file_get_contents($root . '/VERSION'));
        $this->release = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $release) === 1 ? $release : null;
    }

    /**
     * SHOW_OF_HANDS_CONFIG and SHOW_OF_HANDS_DATA point a second copy, such as the one the
     * end-to-end tests run against, at its own config file and data directory.
     */
    public static function fromEnvironment(string $root): self
    {
        $config = getenv('SHOW_OF_HANDS_CONFIG');
        $data = getenv('SHOW_OF_HANDS_DATA');

        return new self($root, is_string($data) && $data !== '' ? $data : null, is_string($config) && $config !== '' ? $config : null);
    }

    public function config(): string
    {
        return $this->configOverride ?? $this->root . '/config/config.php';
    }

    /** The member list an admin may import, next to the config file, so a second copy has its own. */
    public function memberFile(): string
    {
        return dirname($this->config()) . '/members.csv';
    }

    public function data(): string
    {
        return $this->dataOverride ?? $this->root . '/data';
    }

    /**
     * The caches of this release: compiled templates, translations, and the service container.
     * A release ZIP restores its files' build times, which can be older than the old release's
     * cache, so a freshness check alone would keep serving the old files after an update. Each
     * release caches into its own directory instead, and a checkout into dev.
     */
    public function cache(): string
    {
        return $this->data() . '/cache/' . ($this->release ?? 'dev');
    }

    public function templateCache(): string
    {
        return $this->cache() . '/twig';
    }

    public function translationCache(): string
    {
        return $this->cache() . '/translations';
    }

    /**
     * Where the web app compiles its service container, or null in a checkout, whose code changes
     * under a compiled container without a new version.
     */
    public function containerCache(): ?string
    {
        return $this->release === null ? null : $this->cache() . '/container';
    }

    /**
     * The migrations a release ships, from the MIGRATIONS.txt file that bin/build-release writes next
     * to VERSION. Null in a git checkout, which has no such file.
     *
     * @return ?list<string> file names
     */
    public function releaseMigrations(): ?array
    {
        $names = @file($this->root . '/MIGRATIONS.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $names === false ? null : $names;
    }

    public function log(): string
    {
        return $this->data() . '/logs/app.log';
    }

    public function sessions(): string
    {
        return $this->data() . '/sessions';
    }

    public function database(): string
    {
        return $this->data() . '/app.sqlite';
    }

    public function migrations(): string
    {
        return $this->root . '/migrations';
    }

    public function templates(): string
    {
        return $this->root . '/templates';
    }

    public function translations(): string
    {
        return $this->root . '/translations';
    }

    /** Per-instance overrides of single translation keys. */
    public function translationOverrides(): string
    {
        return $this->root . '/config/translations';
    }

    public function public(): string
    {
        return $this->root . '/public';
    }
}
