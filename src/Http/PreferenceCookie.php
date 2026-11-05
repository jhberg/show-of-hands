<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

/**
 * The cookie that keeps a visitor's language or colour mode on this device, also before sign-in.
 */
final class PreferenceCookie
{
    private const DAYS = 365;

    public static function header(string $name, string $value, bool $https): string
    {
        return sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            $name,
            $value,
            self::DAYS * 86400,
            $https ? '; Secure' : '',
        );
    }
}
