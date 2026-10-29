<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('purge', 'Run the lazy maintenance now, for hosts with cron.')]
final class PurgeCommand extends DatabaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->service(Maintenance::class)->run();
        $output->writeln(sprintf('Deleted %d expired session files and %d old backups.', $deleted['sessions'], $deleted['backups']));

        return Command::SUCCESS;
    }
}
