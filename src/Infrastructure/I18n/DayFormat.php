<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\I18n;

/**
 * Formats a day in a language. The skeleton names the fields ("dMMMM"); ICU picks their
 * order and punctuation for the locale, so German reads "3. November", English "November 3".
 */
final class DayFormat
{
    public static function format(\DateTimeInterface $day, string $skeleton, string $locale): string
    {
        $pattern = (string) (new \IntlDatePatternGenerator($locale))->getBestPattern($skeleton);
        $formatted = (new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $day->getTimezone(), null, $pattern))->format($day);

        return is_string($formatted) ? $formatted : $day->format('Y-m-d');
    }
}
