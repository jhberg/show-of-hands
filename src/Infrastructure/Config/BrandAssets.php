<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * Finds the instance's logo and favicon in public/brand/, falling back to the default favicon
 * in public/assets/brand-default/. URLs carry the file time, so a replaced file shows at once.
 */
final readonly class BrandAssets
{
    public function __construct(private string $publicDir) {}

    /** Logo on the login page. Null means the page shows the wordmark instead. */
    public function logo(): ?string
    {
        return $this->find('/brand/logo', ['svg', 'png']);
    }

    /** Header logo. Null means the header shows the wordmark. */
    public function headerLogo(): ?string
    {
        return $this->find('/brand/header-logo', ['svg', 'png']);
    }

    public function favicon(): string
    {
        return $this->find('/brand/favicon', ['png', 'svg']) ?? $this->asset('/assets/brand-default/favicon.png');
    }

    /**
     * Appends the file time to a path under public/, so browsers fetch the file again after an update.
     */
    public function asset(string $path): string
    {
        $mtime = @filemtime($this->publicDir . $path);

        return $mtime === false ? $path : $path . '?v=' . $mtime;
    }

    /**
     * @param list<string> $extensions
     */
    private function find(string $base, array $extensions): ?string
    {
        foreach ($extensions as $extension) {
            if (is_file($this->publicDir . $base . '.' . $extension)) {
                return $this->asset($base . '.' . $extension);
            }
        }

        return null;
    }
}
