<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\Config\Brand;
use ShowOfHands\Infrastructure\Config\FontFile;
use ShowOfHands\Infrastructure\Theme\Color;
use ShowOfHands\Infrastructure\Theme\ThemeBuilder;

final class ThemeBuilderTest extends TestCase
{
    /**
     * Brand colours that push the derivation: light, dark, saturated, grey.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function brands(): iterable
    {
        yield 'default' => [Brand::DEFAULT_ACCENT, Brand::DEFAULT_INK];
        yield 'yellow' => ['#ffe600', '#20233a'];
        yield 'gold' => ['#d4b106', '#20233a'];
        yield 'near black' => ['#111111', '#000000'];
        yield 'pale pink' => ['#ffd1dc', '#5a2a3a'];
        yield 'pure red' => ['#ff0000', '#330000'];
        yield 'grey' => ['#888888', '#888888'];
        yield 'teal' => ['#0f766e', '#134e4a'];
    }

    #[DataProvider('brands')]
    public function testEveryPairMeetsItsContrast(string $accent, string $ink): void
    {
        $theme = (new ThemeBuilder())->build(new Brand('Example', 'Example', '', $accent, $ink));

        foreach ($theme->contrastReport() as $row) {
            self::assertGreaterThanOrEqual(
                $row['min'],
                $row['ratio'],
                sprintf('%s: %s on %s', $row['mode'], $row['text'], $row['background']),
            );
        }
    }

    public function testKeepsTheBrandAccentInLightMode(): void
    {
        $theme = (new ThemeBuilder())->build(new Brand('Example', 'Example', '', '#0f766e', '#134e4a'));

        self::assertSame('#0f766e', $theme->light['accent']->hex());
    }

    public function testRendersFontFaces(): void
    {
        $brand = new Brand('Example', 'Example', '', Brand::DEFAULT_ACCENT, Brand::DEFAULT_INK, 'Inter', [new FontFile('Inter-Bold.woff2', 700)]);

        $css = (new ThemeBuilder())->build($brand)->css();

        self::assertStringContainsString('@font-face{font-family:"Inter";src:url("/brand/fonts/Inter-Bold.woff2") format("woff2");font-weight:700', $css);
        self::assertStringContainsString('--font:"Inter",system-ui', $css);
    }

    public function testRoundTripsHex(): void
    {
        foreach (['#3b6fb6', '#000000', '#ffffff', '#d4b106', '#ff0000'] as $hex) {
            self::assertSame($hex, Color::fromHex($hex)->hex());
        }
    }

    public function testComputesWcagContrast(): void
    {
        self::assertEqualsWithDelta(21.0, Color::fromHex('#000')->contrast(Color::fromHex('#fff')), 0.01);
    }
}
