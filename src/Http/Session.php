<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

/**
 * The visitor's session, started on first use. In production it is PHP's native file session
 * in data/sessions/. Tests use an in-memory session, because PHPUnit sends output before any test runs.
 */
final class Session
{
    /** @var array<string, mixed> */
    private array $memory = [];

    private bool $started = false;

    private function __construct(
        private readonly bool $native,
        private readonly string $savePath = '',
        private readonly int $lifetimeSeconds = 0,
        private readonly bool $secure = false,
    ) {}

    public static function native(string $savePath, int $lifetimeDays, bool $secure): self
    {
        return new self(true, $savePath, $lifetimeDays * 86400, $secure);
    }

    public static function inMemory(): self
    {
        return new self(false);
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        if (!$this->native) {
            return;
        }
        if (!is_dir($this->savePath) && !mkdir($this->savePath, 0o770, true) && !is_dir($this->savePath)) {
            throw new \RuntimeException("Cannot create {$this->savePath}.");
        }
        session_name('soh_session');
        session_save_path($this->savePath);
        session_set_cookie_params([
            'lifetime' => $this->lifetimeSeconds,
            'path' => '/',
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start([
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'gc_maxlifetime' => $this->lifetimeSeconds,
            // Many hosts set gc_probability to 0 and clean up by cron, which never sees data/sessions/.
            'gc_probability' => 1,
            'gc_divisor' => 100,
            // Signed-in responses send their own Cache-Control: no-store.
            'cache_limiter' => '',
        ]);
    }

    public function get(string $key): mixed
    {
        $this->start();

        return $this->native ? ($_SESSION[$key] ?? null) : ($this->memory[$key] ?? null);
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        if ($this->native) {
            $_SESSION[$key] = $value;
        } else {
            $this->memory[$key] = $value;
        }
    }

    public function csrfToken(): string
    {
        $token = $this->get('csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('csrf', $token);
        }

        return $token;
    }

    public function isValidCsrfToken(mixed $sent): bool
    {
        $expected = $this->get('csrf');

        return is_string($sent) && is_string($expected) && hash_equals($expected, $sent);
    }
}
