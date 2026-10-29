<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use ShowOfHands\Infrastructure\Config\Brand;
use ShowOfHands\Infrastructure\Config\BrandAssets;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Infrastructure\Theme\ThemeBuilder;
use ShowOfHands\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('brand:check', 'Print the contrast of every text and background pair of the derived theme.')]
final class BrandCheckCommand extends Command
{
    public function __construct(private readonly Paths $paths)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (is_file($this->paths->config())) {
            $brand = ConfigLoader::fromFile($this->paths->config())->brand;
            $output->writeln(sprintf('Brand "%s": accent %s, ink %s', $brand->name, $brand->accent, $brand->ink));
        } else {
            $brand = new Brand('Default', 'Default', '', Brand::DEFAULT_ACCENT, Brand::DEFAULT_INK);
            $output->writeln(sprintf('No config/config.php, checking the default brand: accent %s, ink %s', $brand->accent, $brand->ink));
        }

        $failures = 0;
        $table = new Table($output);
        $table->setHeaders(['Mode', 'Text', 'Background', 'Ratio', 'Needs', '']);
        foreach ((new ThemeBuilder())->build($brand)->contrastReport() as $row) {
            $ok = $row['ratio'] >= $row['min'];
            $failures += $ok ? 0 : 1;
            $table->addRow([
                $row['mode'],
                $row['text'],
                $row['background'],
                sprintf('%.2f:1', $row['ratio']),
                sprintf('%.1f:1', $row['min']),
                $ok ? 'ok' : '<error>FAIL</error>',
            ]);
        }
        $table->render();

        $assets = new BrandAssets($this->paths->public());
        $output->writeln('Login logo:  ' . ($assets->logo() ?? 'none, the login page shows the wordmark'));
        $output->writeln('Header logo: ' . ($assets->headerLogo() ?? 'none, the header shows the wordmark'));
        $output->writeln('Favicon:     ' . $assets->favicon());

        if ($failures > 0) {
            $output->writeln("<error>{$failures} pairs are below their minimum contrast.</error>");

            return Command::FAILURE;
        }
        $output->writeln('<info>Every pair meets its minimum contrast.</info>');

        return Command::SUCCESS;
    }
}
