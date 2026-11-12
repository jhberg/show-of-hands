<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * Finds the instance's logo and favicon in brand/, falling back to the default favicon
 * in assets/brand-default/. URLs carry the file time, so a replaced file shows at once.
 * A logo may come with a `-dark` variant, which the page shows when the device is in dark mode.
 */
final readonly class BrandAssets
{
    public function __construct(private string $root) {}

    /** Logo on the login page. Null means the page shows the wordmark instead. */
    public function logo(): ?string
    {
        return $this->find('/brand/logo', ['svg', 'png']);
    }

    /** Login logo for dark mode. Null means the login logo shows in both modes. */
    public function logoDark(): ?string
    {
        return $this->find('/brand/logo-dark', ['svg', 'png']);
    }

    /** Header logo. Null means the header shows the wordmark. */
    public function headerLogo(): ?string
    {
        return $this->find('/brand/header-logo', ['svg', 'png']);
    }

    /** Header logo for dark mode. Null means the header logo shows in both modes. */
    public function headerLogoDark(): ?string
    {
        return $this->find('/brand/header-logo-dark', ['svg', 'png']);
    }

    public function favicon(): string
    {
        return $this->find('/brand/favicon', ['png', 'svg']) ?? $this->asset('/assets/brand-default/favicon.png');
    }

    /**
     * Appends the file time to a path under the installation folder, so browsers fetch the file again after an update.
     */
    public function asset(string $path): string
    {
        $mtime = @filemtime($this->root . $path);

        return $mtime === false ? $path : $path . '?v=' . $mtime;
    }

    /**
     * @param list<string> $extensions
     */
    private function find(string $base, array $extensions): ?string
    {
        foreach ($extensions as $extension) {
            if (is_file($this->root . $base . '.' . $extension)) {
                return $this->asset($base . '.' . $extension);
            }
        }

        return null;
    }
}
