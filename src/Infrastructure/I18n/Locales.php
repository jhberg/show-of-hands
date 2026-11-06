<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\I18n;

final class Locales
{
    /**
     * Every locale the UI ships in, in the order the language switch lists them. A new one also
     * needs a migration that adds it to the CHECK on members.locale.
     */
    public const SUPPORTED = ['de', 'en'];

    /**
     * @phpstan-assert-if-true value-of<self::SUPPORTED> $locale
     */
    public static function isSupported(mixed $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }
}
