<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

use ShowOfHands\Infrastructure\I18n\Locales;

/**
 * Reads config/config.php and validates it. Collects every problem before it fails,
 * and names each key the way it appears in the file.
 */
final class ConfigLoader
{
    /** Allowed keys per section. A key outside this list is most likely a typo. */
    private const KEYS = [
        'app' => ['url', 'secret', 'timezone', 'env'],
        'locale' => ['default'],
        'google' => ['client_id', 'client_secret', 'workspace_domain'],
        'brand' => ['name', 'wordmark', 'wordmark_accent', 'colors', 'font'],
        'brand.colors' => ['accent', 'ink'],
        'brand.font' => ['family', 'files'],
        'bootstrap_admin' => ['name', 'email', 'group'],
        'session' => ['lifetime_days'],
    ];

    /** @var list<string> */
    private array $errors = [];

    private function __construct(private readonly string $path) {}

    public static function fromFile(string $path): Config
    {
        if (!is_file($path)) {
            throw new ConfigException($path, ['The file does not exist. Copy config/config.example.php to config/config.php and fill in the values.']);
        }
        $data = (static fn(): mixed => require $path)();
        if (!is_array($data)) {
            throw new ConfigException($path, ['The file must return an array (return [ ... ];).']);
        }

        return (new self($path))->build($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data, string $path = 'config/config.php'): Config
    {
        return (new self($path))->build($data);
    }

    /**
     * @param array<mixed> $data
     */
    private function build(array $data): Config
    {
        $this->checkKeys($data);

        $url = $this->string($data, 'app.url');
        $url = $url === null || $url === '' ? '' : $this->appUrl($url);
        $env = $this->string($data, 'app.env', 'prod');
        if (!in_array($env, ['prod', 'dev'], true)) {
            $this->errors[] = "app.env must be 'prod' or 'dev'.";
        }
        $host = parse_url($url, PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);
        if ($env === 'dev' && $url !== '' && !$local) {
            $this->errors[] = "app.env 'dev' enables the dev login, so app.url must point at localhost or 127.0.0.1.";
        }
        if ($env === 'prod' && $url !== '' && !$local && !str_starts_with($url, 'https://')) {
            $this->errors[] = 'app.url must start with https:// in production.';
        }

        $secret = $this->string($data, 'app.secret') ?? '';
        if ($secret !== '' && preg_match('/^[0-9a-f]{64,}$/i', $secret) !== 1) {
            $this->errors[] = "app.secret must be at least 64 hex characters (32 random bytes). Generate one with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'";
        }

        $timezone = $this->string($data, 'app.timezone') ?? '';
        if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $this->errors[] = "app.timezone '{$timezone}' is not a known time zone, for example 'Europe/Berlin'.";
        }

        $locale = $this->string($data, 'locale.default', 'de');
        if (!Locales::isSupported($locale)) {
            $this->errors[] = "locale.default must be '" . implode("' or '", Locales::SUPPORTED) . "'.";
        }

        // The dev login replaces Google, so a development copy runs without a Google client.
        $googleDefault = $env === 'dev' ? '' : null;
        $clientId = $this->string($data, 'google.client_id', $googleDefault) ?? '';
        $clientSecret = $this->string($data, 'google.client_secret', $googleDefault) ?? '';
        $domain = $this->string($data, 'google.workspace_domain', '');

        $brand = $this->brand($data);

        $lifetime = $this->get($data, 'session.lifetime_days') ?? 30;
        if (!is_int($lifetime) || $lifetime < 1 || $lifetime > 365) {
            $this->errors[] = 'session.lifetime_days must be a whole number of days between 1 and 365.';
            $lifetime = 30;
        }

        $admin = $this->get($data, 'bootstrap_admin');
        if ($admin !== null && !is_array($admin)) {
            $this->errors[] = "bootstrap_admin must be an array with 'name', 'email', and 'group'.";
            $admin = null;
        }

        if ($this->errors !== []) {
            throw new ConfigException($this->path, $this->errors);
        }

        /** @var array<string, mixed>|null $admin */
        return new Config(
            appUrl: $url,
            secret: $secret,
            timezone: $timezone,
            env: $env,
            defaultLocale: $locale,
            googleClientId: $clientId,
            googleClientSecret: $clientSecret,
            workspaceDomain: $domain === '' ? null : $domain,
            brand: $brand,
            sessionLifetimeDays: $lifetime,
            bootstrapAdmin: $admin,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function brand(array $data): Brand
    {
        $name = $this->string($data, 'brand.name') ?? '';
        $wordmark = $this->string($data, 'brand.wordmark', '');
        $wordmarkAccent = $this->string($data, 'brand.wordmark_accent', '');
        $accent = $this->color($data, 'brand.colors.accent', Brand::DEFAULT_ACCENT);
        $ink = $this->color($data, 'brand.colors.ink', Brand::DEFAULT_INK);

        $family = $this->string($data, 'brand.font.family', '');
        if ($family !== '' && preg_match('/^[\p{L}\p{N} _-]+$/u', $family) !== 1) {
            $this->errors[] = 'brand.font.family may contain only letters, digits, spaces, hyphens, and underscores.';
        }
        $files = [];
        $rawFiles = $this->get($data, 'brand.font.files') ?? [];
        if (!is_array($rawFiles) || !array_is_list($rawFiles)) {
            $this->errors[] = "brand.font.files must be a list, for example [['file' => 'Inter-Regular.woff2', 'weight' => 400]].";
            $rawFiles = [];
        }
        foreach ($rawFiles as $i => $entry) {
            $font = $this->fontFile($entry, "brand.font.files[{$i}]");
            if ($font !== null) {
                $files[] = $font;
            }
        }
        if ($files !== [] && $family === '') {
            $this->errors[] = 'brand.font.family is required when brand.font.files lists files.';
        }

        return new Brand(
            name: $name,
            wordmark: $wordmark === '' ? $name : $wordmark,
            wordmarkAccent: $wordmarkAccent,
            accent: $accent,
            ink: $ink,
            fontFamily: $family === '' ? null : $family,
            fontFiles: $files,
        );
    }

    private function fontFile(mixed $entry, string $key): ?FontFile
    {
        if (is_string($entry)) {
            $entry = ['file' => $entry];
        }
        if (!is_array($entry)) {
            $this->errors[] = "{$key} must be a file name or an array with 'file', 'weight', and 'style'.";

            return null;
        }
        $file = $entry['file'] ?? null;
        $weight = $entry['weight'] ?? 400;
        $style = $entry['style'] ?? 'normal';
        if (!is_string($file) || preg_match('/^[A-Za-z0-9._-]+\.(woff2|woff)$/', $file) !== 1) {
            $this->errors[] = "{$key}.file must be a .woff2 or .woff file name in public/brand/fonts/.";

            return null;
        }
        if (!is_int($weight) || $weight < 100 || $weight > 900) {
            $this->errors[] = "{$key}.weight must be a number between 100 and 900.";

            return null;
        }
        if (!in_array($style, ['normal', 'italic'], true)) {
            $this->errors[] = "{$key}.style must be 'normal' or 'italic'.";

            return null;
        }

        return new FontFile($file, $weight, $style);
    }

    private function appUrl(string $url): string
    {
        $url = rtrim($url, '/');
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            $this->errors[] = "app.url must be an absolute URL such as 'https://vote.example.org'.";

            // An empty URL skips the checks that build on a valid one.
            return '';
        }
        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            $this->errors[] = 'app.url must not have a path. The app runs at the root of its own (sub)domain.';
        }

        return $url;
    }

    /**
     * @param array<mixed> $data
     */
    private function color(array $data, string $key, string $default): string
    {
        $value = $this->string($data, $key, $default);
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value) !== 1) {
            $this->errors[] = "{$key} must be a hex colour such as '#3b6fb6'.";

            return $default;
        }

        return strtolower($value);
    }

    /**
     * Reads a string. Without a default, the key is required and must not be empty.
     *
     * @param array<mixed> $data
     *
     * @return ($default is null ? string|null : string)
     */
    private function string(array $data, string $key, ?string $default = null): ?string
    {
        $value = $this->get($data, $key);
        if ($value === null) {
            if ($default === null) {
                $this->errors[] = "{$key} is required.";
            }

            return $default;
        }
        if (!is_string($value)) {
            $this->errors[] = "{$key} must be a string.";

            return $default;
        }
        $value = trim($value);
        if ($value === '' && $default === null) {
            $this->errors[] = "{$key} must not be empty.";
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function get(array $data, string $key): mixed
    {
        $node = $data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<mixed> $data
     */
    private function checkKeys(array $data): void
    {
        $sections = implode(', ', array_filter(array_keys(self::KEYS), static fn(string $k): bool => !str_contains($k, '.')));
        foreach ($data as $section => $value) {
            if (!is_string($section) || str_contains($section, '.') || !array_key_exists($section, self::KEYS)) {
                $this->errors[] = "Unknown section '{$section}'. Known sections: {$sections}.";
                continue;
            }
            if (!is_array($value)) {
                $this->errors[] = "{$section} must be an array.";
                continue;
            }
            $this->checkSectionKeys($section, $value);
        }
    }

    /**
     * @param array<mixed> $value
     */
    private function checkSectionKeys(string $section, array $value): void
    {
        foreach ($value as $key => $child) {
            $path = "{$section}.{$key}";
            if (!in_array($key, self::KEYS[$section], true)) {
                $this->errors[] = "Unknown key '{$path}'.";
                continue;
            }
            if (array_key_exists($path, self::KEYS)) {
                if (!is_array($child)) {
                    $this->errors[] = "{$path} must be an array.";
                    continue;
                }
                $this->checkSectionKeys($path, $child);
            }
        }
    }
}
