<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Config;

/**
 * Name, wordmark, colours, and font of one choir. Logo and favicon are files in public/brand/.
 */
final readonly class Brand
{
    public const DEFAULT_ACCENT = '#3b6fb6';
    public const DEFAULT_INK = '#1f2433';

    /**
     * @param list<FontFile> $fontFiles
     */
    public function __construct(
        public string $name,
        public string $wordmark,
        public string $wordmarkAccent,
        public string $accent,
        public string $ink,
        public ?string $fontFamily = null,
        public array $fontFiles = [],
    ) {}

    /**
     * Splits the wordmark into runs, flagging the characters drawn in the accent colour.
     *
     * @return list<array{text: string, accent: bool}>
     */
    public function wordmarkRuns(): array
    {
        $accentChars = mb_str_split($this->wordmarkAccent);
        $runs = [];
        foreach (mb_str_split($this->wordmark) as $char) {
            $accent = in_array($char, $accentChars, true);
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['accent'] === $accent) {
                $runs[$last]['text'] .= $char;
            } else {
                $runs[] = ['text' => $char, 'accent' => $accent];
            }
        }

        return $runs;
    }
}
