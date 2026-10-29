<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Infrastructure\Config\Brand;
use ShowOfHands\Infrastructure\Config\BrandAssets;
use ShowOfHands\Infrastructure\I18n\Locales;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig globals for the brand and the locales, and the functions asset(), icon(), and csrf_token().
 */
final class TwigExtension extends AbstractExtension implements GlobalsInterface
{
    /** @var array<string, string> */
    private array $icons = [];

    public function __construct(
        private readonly Brand $brand,
        private readonly BrandAssets $assets,
        private readonly string $themeVersion,
        private readonly string $iconDir,
        private readonly Session $session,
    ) {}

    public function getGlobals(): array
    {
        return [
            'brand' => $this->brand,
            'brand_assets' => $this->assets,
            'locales' => Locales::SUPPORTED,
            'theme_url' => ThemeAction::PATH . '?v=' . $this->themeVersion,
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset', $this->assets->asset(...)),
            // The SVG files ship with the app and hold no user input, so they print unescaped.
            new TwigFunction('icon', $this->icon(...), ['is_safe' => ['html']]),
            new TwigFunction('csrf_token', $this->session->csrfToken(...)),
        ];
    }

    private function icon(string $name): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid icon name '{$name}'.");
        }
        if (!isset($this->icons[$name])) {
            $svg = file_get_contents("{$this->iconDir}/{$name}.svg");
            if ($svg === false) {
                throw new \InvalidArgumentException("Unknown icon '{$name}'.");
            }
            $this->icons[$name] = trim($svg);
        }

        return $this->icons[$name];
    }
}
