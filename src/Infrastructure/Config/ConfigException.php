<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * Raised when config/config.php is missing or invalid. Carries every problem found,
 * so the admin can fix the file in one pass.
 */
final class ConfigException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly string $path, public readonly array $errors)
    {
        parent::__construct(sprintf("%s is invalid:\n- %s", $path, implode("\n- ", $errors)));
    }
}
