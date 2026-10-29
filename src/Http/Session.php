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

    private string $memoryId = 'initial';

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
        $cookie = ['path' => '/', 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax'];
        session_set_cookie_params(['lifetime' => $this->lifetimeSeconds] + $cookie);
        $started = session_start([
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'gc_maxlifetime' => $this->lifetimeSeconds,
            // Lazy maintenance deletes expired session files, because many hosts switch PHP's
            // collection off and clean up by cron, which never sees data/sessions/.
            'gc_probability' => 0,
            // Signed-in responses send their own Cache-Control: no-store.
            'cache_limiter' => '',
        ]);
        if (!$started) {
            // Without this, an unwritable session directory shows up only as every form "expiring".
            throw new \RuntimeException("Cannot start the session in {$this->savePath}.");
        }
        // PHP sends the cookie only for a new session. Renewing it on every visit makes the
        // lifetime count from the member's last visit, not from sign-in.
        if (($_COOKIE[session_name()] ?? null) === session_id()) {
            setcookie((string) session_name(), (string) session_id(), ['expires' => time() + $this->lifetimeSeconds] + $cookie);
        }
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

    /**
     * Returns the value and removes it, for one-time values such as a flash message.
     */
    public function take(string $key): mixed
    {
        $value = $this->get($key);
        $this->remove($key);

        return $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        if ($this->native) {
            unset($_SESSION[$key]);
        } else {
            unset($this->memory[$key]);
        }
    }

    /**
     * Gives the session a new id and a new CSRF token and deletes the old id, so neither
     * an id nor a token that was known before sign-in is worth anything afterwards.
     */
    public function regenerate(): void
    {
        $this->remove('csrf');
        if ($this->native) {
            session_regenerate_id(true);
        } else {
            $this->memoryId = bin2hex(random_bytes(16));
        }
    }

    /**
     * Forgets everything, the CSRF token included, and continues under a new id.
     */
    public function clear(): void
    {
        $this->start();
        if ($this->native) {
            $_SESSION = [];
        } else {
            $this->memory = [];
        }
        $this->regenerate();
    }

    /** The in-memory session's id, so tests can see that it changed. */
    public function memoryId(): string
    {
        return $this->memoryId;
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
