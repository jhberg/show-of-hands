<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Theme;

/**
 * A colour in OKLCH, with conversion to and from sRGB hex and WCAG contrast.
 *
 * The OKLab matrices are Björn Ottosson's (https://bottosson.github.io/posts/oklab/).
 */
final readonly class Color
{
    public function __construct(
        public float $l,
        public float $c,
        public float $h,
    ) {}

    public static function fromHex(string $hex): self
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (preg_match('/^[0-9a-f]{6}$/i', $hex) !== 1) {
            throw new \InvalidArgumentException("Not a hex colour: #{$hex}");
        }
        [$r, $g, $b] = array_map(
            static fn(string $pair): float => self::toLinear(hexdec($pair) / 255),
            str_split($hex, 2),
        );

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        $l = self::cbrt($l);
        $m = self::cbrt($m);
        $s = self::cbrt($s);

        $okL = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $okA = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $okB = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $c = sqrt($okA * $okA + $okB * $okB);
        $h = rad2deg(atan2($okB, $okA));

        return new self($okL, $c, $h < 0 ? $h + 360 : $h);
    }

    /**
     * Builds a colour and reduces its chroma until it fits into sRGB, keeping lightness and hue.
     */
    public static function inGamut(float $l, float $c, float $h): self
    {
        $l = max(0.0, min(1.0, $l));
        $c = max(0.0, $c);
        if ((new self($l, $c, $h))->fitsSrgb()) {
            return new self($l, $c, $h);
        }
        $low = 0.0;
        $high = $c;
        for ($i = 0; $i < 24; $i++) {
            $mid = ($low + $high) / 2;
            if ((new self($l, $mid, $h))->fitsSrgb()) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return new self($l, $low, $h);
    }

    public function withL(float $l): self
    {
        return self::inGamut($l, $this->c, $this->h);
    }

    /**
     * @return array{float, float, float} linear sRGB, unclamped
     */
    private function linearRgb(): array
    {
        $rad = deg2rad($this->h);
        $a = $this->c * cos($rad);
        $b = $this->c * sin($rad);

        $l = ($this->l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($this->l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($this->l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    private function fitsSrgb(): bool
    {
        foreach ($this->linearRgb() as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{int, int, int} 0–255 per channel
     */
    public function rgb(): array
    {
        return array_map(
            static fn(float $v): int => (int) round(max(0.0, min(1.0, self::toGamma($v))) * 255),
            $this->linearRgb(),
        );
    }

    public function hex(): string
    {
        return vsprintf('#%02x%02x%02x', $this->rgb());
    }

    /**
     * WCAG 2 relative luminance of the hex value the browser will paint.
     */
    public function luminance(): float
    {
        [$r, $g, $b] = array_map(static fn(int $v): float => self::toLinear($v / 255), $this->rgb());

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public function contrast(self $other): float
    {
        $a = $this->luminance();
        $b = $other->luminance();

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function toLinear(float $v): float
    {
        return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }

    private static function toGamma(float $v): float
    {
        return $v <= 0.0031308 ? 12.92 * $v : 1.055 * ($v ** (1 / 2.4)) - 0.055;
    }

    private static function cbrt(float $v): float
    {
        return $v < 0 ? -((-$v) ** (1 / 3)) : $v ** (1 / 3);
    }
}
