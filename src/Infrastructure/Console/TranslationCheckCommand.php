<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use ShowOfHands\Infrastructure\I18n\TranslationCheck;
use ShowOfHands\Paths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('translation:check', 'Check that both languages have the same keys and that every used key exists.')]
final class TranslationCheckCommand extends Command
{
    public function __construct(private readonly Paths $paths)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $check = new TranslationCheck(
            $this->paths->translations(),
            [$this->paths->templates(), $this->paths->root . '/src'],
            $this->paths->translationOverrides(),
        );
        $problems = $check->problems();
        foreach ($problems as $problem) {
            $output->writeln("<error>{$problem}</error>");
        }
        if ($problems !== []) {
            return Command::FAILURE;
        }
        $output->writeln('<info>Translations are complete.</info>');

        return Command::SUCCESS;
    }
}
