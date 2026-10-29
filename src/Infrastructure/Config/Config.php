<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * The validated instance configuration from config/config.php.
 */
final readonly class Config
{
    /**
     * @param array<string, mixed>|null $bootstrapAdmin validated by the sign-in setup, not here
     */
    public function __construct(
        public string $appUrl,
        public string $secret,
        public string $timezone,
        public string $env,
        public string $defaultLocale,
        public string $googleClientId,
        public string $googleClientSecret,
        public ?string $workspaceDomain,
        public Brand $brand,
        public int $sessionLifetimeDays,
        public ?array $bootstrapAdmin = null,
    ) {}

    public function isDev(): bool
    {
        return $this->env === 'dev';
    }

    public function isHttps(): bool
    {
        return str_starts_with($this->appUrl, 'https://');
    }
}
