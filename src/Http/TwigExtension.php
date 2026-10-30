<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Infrastructure\Config\Brand;
use ShowOfHands\Infrastructure\Config\BrandAssets;
use ShowOfHands\Infrastructure\I18n\DayFormat;
use ShowOfHands\Infrastructure\I18n\Locales;
use Symfony\Component\Translation\Translator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig globals for the brand and the locales, the functions asset(), icon(), and csrf_token(),
 * the filters day_long, day_date, and day_short for dates without time, percent, and name_list.
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
        private readonly Translator $translator,
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

    public function getFilters(): array
    {
        return [
            // "Di., 3. November 2026"
            new TwigFilter('day_long', fn(string $date): string => $this->formatDay($date, 'EEEdMMMMy')),
            // "3. November 2026"
            new TwigFilter('day_date', fn(string $date): string => $this->formatDay($date, 'dMMMMy')),
            // "3. Nov."
            new TwigFilter('day_short', fn(string $date): string => $this->formatDay($date, 'dMMM')),
            // A share between 0 and 1 as "68,0 %"
            new TwigFilter('percent', $this->percent(...)),
            // "Lina, Ida und Pia"
            new TwigFilter('name_list', $this->nameList(...)),
        ];
    }

    private function percent(float $share): string
    {
        $format = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::PERCENT);
        $format->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 1);
        $format->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 1);

        return (string) $format->format($share);
    }

    /**
     * @param list<string> $names
     */
    private function nameList(array $names): string
    {
        if (count($names) < 2) {
            return implode('', $names);
        }
        $last = array_pop($names);

        return $this->translator->trans('list.and', ['names' => implode(', ', $names), 'last' => $last]);
    }

    /**
     * Formats a Y-m-d date in the active language.
     */
    private function formatDay(string $date, string $skeleton): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if ($day === false) {
            throw new \InvalidArgumentException("Invalid date '{$date}'.");
        }

        return DayFormat::format($day, $skeleton, $this->translator->getLocale());
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
