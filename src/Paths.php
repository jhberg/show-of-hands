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
    ) {}

    public function config(): string
    {
        return $this->root . '/config/config.php';
    }

    public function data(): string
    {
        return $this->dataOverride ?? $this->root . '/data';
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
