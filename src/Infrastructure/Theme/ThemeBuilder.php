<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Theme;

use ShowOfHands\Infrastructure\Config\Brand;

/**
 * Derives the full colour token set from a brand's accent and ink colour, for light and dark mode.
 *
 * The lightness and chroma values come from the reference design. The accent tokens take
 * the brand's hue, the neutrals take the ink's hue. Text tokens move away from their
 * background until they reach 4.5:1, so any brand colour yields a readable theme.
 */
final class ThemeBuilder
{
    /** Bump when the derivation changes, so browsers drop their cached /theme.css. */
    public const VERSION = 2;

    public const TEXT_CONTRAST = 4.5;
    public const UI_CONTRAST = 3.0;

    /** Chroma of the reference accent and ink. Brand chroma scales the derived tints relative to these. */
    private const REF_ACCENT_C = 0.155;
    private const REF_INK_C = 0.035;

    public function build(Brand $brand): Theme
    {
        $accent = Color::fromHex($brand->accent);
        $ink = Color::fromHex($brand->ink);
        $ka = min(1.2, $accent->c / self::REF_ACCENT_C);
        $kn = min(1.5, $ink->c / self::REF_INK_C);

        return new Theme(
            light: $this->light($accent, $ink, $ka, $kn),
            dark: $this->dark($accent, $ink, $ka, $kn),
            fontFamily: $brand->fontFamily,
            fontFiles: $brand->fontFiles,
        );
    }

    /**
     * @return array<string, Color>
     */
    private function light(Color $accent, Color $ink, float $ka, float $kn): array
    {
        $n = static fn(float $l, float $c): Color => Color::inGamut($l, $c * $kn, $ink->h);
        $a = static fn(float $l, float $c): Color => Color::inGamut($l, $c * $ka, $accent->h);

        $t = [
            'bg' => Color::inGamut(1, 0, 0),
            'surface' => $n(0.975, 0.004),
            'surface-2' => $n(0.945, 0.007),
            'surface-3' => $n(0.9, 0.008),
            'line' => $n(0.87, 0.01),
            'line-2' => $n(0.925, 0.007),
            'accent' => Color::inGamut($accent->l, $accent->c, $accent->h),
            'accent-soft' => $a(0.965, 0.05),
            'accent-line' => $a(0.85, 0.11),
        ];
        $t['ink'] = self::darken(Color::inGamut(min($ink->l, 0.35), $ink->c, $ink->h), [$t['surface-2'], $t['accent-soft']], self::TEXT_CONTRAST);
        $t['ink-2'] = self::darken($n(0.46, 0.028), [$t['surface-2'], $t['accent-soft']], self::TEXT_CONTRAST);
        $t['ink-3'] = self::darken($n(0.54, 0.022), [$t['bg'], $t['surface']], self::TEXT_CONTRAST);
        $t['accent-text'] = self::darken($a(0.5, 0.11), [$t['bg'], $t['accent-soft'], $t['surface']], self::TEXT_CONTRAST);
        $t['on-accent'] = self::onAccent($t['accent'], $n(0.22, 0.03));
        $t['accent-hover'] = self::readableHover(Color::inGamut($accent->l - 0.05, $accent->c * 0.97, $accent->h), $t['on-accent']);
        $t['focus'] = self::darken($a(0.62, 0.15), [$t['bg']], self::UI_CONTRAST);

        // Yes and No keep fixed colours, so a result never reads differently between choirs.
        return $t + [
            'ja' => Color::inGamut(0.5, 0.13, 152),
            'ja-soft' => Color::inGamut(0.955, 0.04, 152),
            'ja-line' => Color::inGamut(0.8, 0.1, 152),
            'nein' => Color::inGamut(0.5, 0.17, 28),
            'nein-soft' => Color::inGamut(0.955, 0.03, 28),
            'nein-line' => Color::inGamut(0.82, 0.09, 28),
        ];
    }

    /**
     * @return array<string, Color>
     */
    private function dark(Color $accent, Color $ink, float $ka, float $kn): array
    {
        $n = static fn(float $l, float $c): Color => Color::inGamut($l, $c * $kn, $ink->h);
        $a = static fn(float $l, float $c): Color => Color::inGamut($l, $c * $ka, $accent->h);

        $t = [
            'bg' => $n(0.19, 0.022),
            'surface' => $n(0.23, 0.022),
            'surface-2' => $n(0.28, 0.022),
            'surface-3' => $n(0.34, 0.022),
            'line' => $n(0.36, 0.02),
            'line-2' => $n(0.3, 0.02),
            'accent-soft' => $a(0.3, 0.05),
            'accent-line' => $a(0.45, 0.08),
        ];
        // A dark brand colour would vanish on the dark background, so the accent never drops below 0.72.
        $accentL = max($accent->l, 0.72);
        $t['accent'] = Color::inGamut($accentL, $accent->c, $accent->h);
        $t['ink'] = self::lighten($n(0.94, 0.006), [$t['surface-2'], $t['accent-soft']], self::TEXT_CONTRAST);
        $t['ink-2'] = self::lighten($n(0.76, 0.012), [$t['surface-2'], $t['accent-soft']], self::TEXT_CONTRAST);
        $t['ink-3'] = self::lighten($n(0.68, 0.012), [$t['bg'], $t['surface']], self::TEXT_CONTRAST);
        $t['accent-text'] = self::lighten($a(0.82, 0.14), [$t['bg'], $t['accent-soft'], $t['surface']], self::TEXT_CONTRAST);
        $t['on-accent'] = self::onAccent($t['accent'], $n(0.2, 0.03));
        $t['accent-hover'] = self::readableHover(Color::inGamut($accentL + 0.05, $accent->c, $accent->h), $t['on-accent']);
        $t['focus'] = self::lighten($a(0.8, 0.15), [$t['bg']], self::UI_CONTRAST);

        return $t + [
            'ja' => Color::inGamut(0.78, 0.13, 152),
            'ja-soft' => Color::inGamut(0.28, 0.05, 152),
            'ja-line' => Color::inGamut(0.45, 0.08, 152),
            'nein' => Color::inGamut(0.78, 0.13, 28),
            'nein-soft' => Color::inGamut(0.3, 0.05, 28),
            'nein-line' => Color::inGamut(0.48, 0.09, 28),
        ];
    }

    /**
     * Dark ink or white on the accent, whichever reads better.
     * On a mid-tone accent the dark ink first darkens towards black until it reaches 4.5:1.
     */
    private static function onAccent(Color $accent, Color $darkInk): Color
    {
        $darkInk = self::darken($darkInk, [$accent], self::TEXT_CONTRAST);
        $white = Color::inGamut(1, 0, 0);

        return $darkInk->contrast($accent) >= $white->contrast($accent) ? $darkInk : $white;
    }

    /**
     * Moves the hover colour away from the button text until the text stays readable on it.
     */
    private static function readableHover(Color $hover, Color $text): Color
    {
        return $text->l < 0.5
            ? self::lighten($hover, [$text], self::TEXT_CONTRAST)
            : self::darken($hover, [$text], self::TEXT_CONTRAST);
    }

    /**
     * @param non-empty-list<Color> $backgrounds
     */
    private static function darken(Color $color, array $backgrounds, float $min): Color
    {
        return self::shift($color, $backgrounds, $min, -0.005);
    }

    /**
     * @param non-empty-list<Color> $backgrounds
     */
    private static function lighten(Color $color, array $backgrounds, float $min): Color
    {
        return self::shift($color, $backgrounds, $min, 0.005);
    }

    /**
     * @param non-empty-list<Color> $backgrounds
     */
    private static function shift(Color $color, array $backgrounds, float $min, float $step): Color
    {
        while ($color->l > 0 && $color->l < 1) {
            $worst = min(array_map(static fn(Color $bg): float => $color->contrast($bg), $backgrounds));
            if ($worst >= $min) {
                break;
            }
            $color = $color->withL($color->l + $step);
        }

        return $color;
    }
}
