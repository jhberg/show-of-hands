<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use ShowOfHands\Domain\EmailAddress;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Persistence\DuplicateEmailException;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('member:add', 'Add a member, for example the first admin where a shell exists.')]
final class MemberAddCommand extends DatabaseCommand
{
    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Full name, for example "Alex Example"')
            ->addArgument('email', InputArgument::REQUIRED, 'The address the member signs in with')
            ->addOption('email2', null, InputOption::VALUE_REQUIRED, 'A second address, for example a private account')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, 'Voice group key, for example S1')
            ->addOption('board', null, InputOption::VALUE_NONE, 'May close polls')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'May set up polls, members, and settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = trim(self::text($input->getArgument('name')) ?? '');
        if ($name === '') {
            $output->writeln('<error>The name must not be empty.</error>');

            return Command::INVALID;
        }
        $emails = [];
        foreach ([self::text($input->getArgument('email')), self::text($input->getOption('email2'))] as $raw) {
            if ($raw === null) {
                continue;
            }
            $email = EmailAddress::normalize($raw);
            if ($email === null) {
                $output->writeln(sprintf('<error>"%s" is not a valid email address.</error>', $raw));

                return Command::INVALID;
            }
            $emails[] = $email;
        }
        if (count(array_unique($emails)) !== count($emails)) {
            $output->writeln('<error>The two addresses are the same.</error>');

            return Command::INVALID;
        }
        $group = self::text($input->getOption('group'));
        $groups = $this->service(VoiceGroupRepository::class);
        if ($group !== null && !$groups->exists($group)) {
            $keys = array_map(static fn(VoiceGroup $g): string => $g->key, $groups->all());
            $output->writeln(sprintf('<error>There is no voice group "%s". Known keys: %s.</error>', $group, implode(', ', $keys)));

            return Command::INVALID;
        }

        try {
            $id = $this->service(MemberRepository::class)->add($name, $emails, $group, (bool) $input->getOption('board'), (bool) $input->getOption('admin'));
        } catch (DuplicateEmailException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Added %s (member %d).</info>', $name, $id));

        return Command::SUCCESS;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
