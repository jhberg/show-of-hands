<?php

declare(strict_types=1);

namespace ShowOfHands;

/**
 * Where the app finds its code, instance files, and runtime data.
 */
final readonly class Paths
{
    public function __construct(
        public string $root,
        public ?string $dataOverride = null,
        public ?string $configOverride = null,
    ) {}

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

    public function data(): string
    {
        return $this->dataOverride ?? $this->root . '/data';
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
