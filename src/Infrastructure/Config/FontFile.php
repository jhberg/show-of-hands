<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * One self-hosted font file in public/brand/fonts/.
 */
final readonly class FontFile
{
    public function __construct(
        public string $file,
        public int $weight = 400,
        public string $style = 'normal',
    ) {}
}
