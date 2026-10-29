<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Theme;

use ShowOfHands\Infrastructure\Config\FontFile;

/**
 * The derived token set of one brand. Renders /theme.css and lists the contrast pairs for brand:check.
 */
final readonly class Theme
{
    private const SYSTEM_FONTS = 'system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif';

    /**
     * Text and background token pairs the stylesheet combines, with the contrast each needs.
     * The focus ring is not text, so it needs 3:1 against the page.
     */
    private const PAIRS = [
        ['ink', 'bg'], ['ink', 'surface'], ['ink', 'surface-2'], ['ink', 'accent-soft'],
        ['ink-2', 'bg'], ['ink-2', 'surface'], ['ink-2', 'surface-2'], ['ink-2', 'accent-soft'],
        ['ink-3', 'bg'], ['ink-3', 'surface'],
        ['accent-text', 'bg'], ['accent-text', 'surface'], ['accent-text', 'accent-soft'],
        ['on-accent', 'accent'], ['on-accent', 'accent-hover'],
        ['bg', 'ink'],
        ['ja', 'bg'], ['ja', 'ja-soft'],
        ['nein', 'bg'], ['nein', 'nein-soft'],
        ['focus', 'bg', ThemeBuilder::UI_CONTRAST],
    ];

    /**
     * @param array<string, Color> $light
     * @param array<string, Color> $dark
     * @param list<FontFile> $fontFiles self-hosted files of $fontFamily
     */
    public function __construct(
        public array $light,
        public array $dark,
        public ?string $fontFamily = null,
        public array $fontFiles = [],
    ) {}

    private function fontStack(): string
    {
        return ($this->fontFamily === null ? '' : '"' . $this->fontFamily . '",') . self::SYSTEM_FONTS;
    }

    public function css(): string
    {
        $css = "/* Generated from brand.colors and brand.font in config/config.php. */\n";
        foreach ($this->fontFiles as $file) {
            $format = str_ends_with($file->file, '.woff2') ? 'woff2' : 'woff';
            $css .= sprintf(
                "@font-face{font-family:\"%s\";src:url(\"/brand/fonts/%s\") format(\"%s\");font-weight:%d;font-style:%s;font-display:swap}\n",
                $this->fontFamily,
                $file->file,
                $format,
                $file->weight,
                $file->style,
            );
        }
        $css .= ':root{color-scheme:light;' . $this->tokens($this->light)
            . '--shadow:' . $this->shadow($this->light['ink'], 0.06, 0.22) . ';'
            . '--font:' . $this->fontStack() . "}\n";
        $css .= '@media (prefers-color-scheme: dark){:root{color-scheme:dark;' . $this->tokens($this->dark)
            . '--shadow:' . $this->shadow(Color::inGamut(0, 0, 0), 0.3, 0.5) . "}}\n";

        return $css;
    }

    /**
     * @return list<array{mode: string, text: string, background: string, ratio: float, min: float}>
     */
    public function contrastReport(): array
    {
        $rows = [];
        foreach (['light' => $this->light, 'dark' => $this->dark] as $mode => $tokens) {
            foreach (self::PAIRS as $pair) {
                $rows[] = [
                    'mode' => $mode,
                    'text' => $pair[0],
                    'background' => $pair[1],
                    'ratio' => $tokens[$pair[0]]->contrast($tokens[$pair[1]]),
                    'min' => $pair[2] ?? ThemeBuilder::TEXT_CONTRAST,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, Color> $tokens
     */
    private function tokens(array $tokens): string
    {
        ksort($tokens);
        $css = '';
        foreach ($tokens as $name => $color) {
            $css .= "--{$name}:{$color->hex()};";
        }

        return $css;
    }

    private function shadow(Color $color, float $near, float $far): string
    {
        [$r, $g, $b] = $color->rgb();

        return sprintf('0 1px 2px rgb(%d %d %d / %s),0 8px 24px -12px rgb(%d %d %d / %s)', $r, $g, $b, $near, $r, $g, $b, $far);
    }
}
