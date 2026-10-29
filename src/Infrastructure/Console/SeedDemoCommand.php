<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Database\Transaction;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Replaces all data with the sample choir of the reference mockup: 25 invented members in nine
 * voice groups, some with a second address and with board or admin roles.
 */
#[AsCommand('seed:demo', 'Replace all data with sample data (development only).')]
final class SeedDemoCommand extends DatabaseCommand
{
    private const PRIMARY_DOMAIN = 'example.org';
    private const PRIVATE_DOMAIN = 'example.net';

    /**
     * Name, voice group (null for the director's group), and options. 'private' adds a second address.
     *
     * @var list<array{string, string|null, array{admin?: bool, board?: bool, private?: bool}}>
     */
    private const MEMBERS = [
        ['Mara Kessler', 'S1', ['admin' => true, 'board' => true, 'private' => true]],
        ['Lina Hofer', 'S1', []],
        ['Ida Brandl', 'S1', []],
        ['Greta Sommerfeld', 'S1', ['private' => true]],
        ['Pia Lechner', 'S2', []],
        ['Jana Wirth', 'S2', []],
        ['Ella Moser', 'S2', []],
        ['Tilda Ebert', 'A1', []],
        ['Rosa Kühn', 'A1', []],
        ['Frieda Haas', 'A1', []],
        ['Emma Pohl', 'A2', ['private' => true]],
        ['Lotte Brenner', 'A2', []],
        ['Hedi Graf', 'A2', []],
        ['Jonas Falk', 'T1', ['admin' => true, 'board' => true]],
        ['Theo Reinhardt', 'T1', []],
        ['Matti Busch', 'T1', []],
        ['Ben Engel', 'T2', []],
        ['Paul Arnold', 'T2', ['private' => true]],
        ['Max Seidel', 'B1', ['board' => true, 'private' => true]],
        ['Kai Böhm', 'B1', []],
        ['Erik Voss', 'B1', []],
        ['Leo Kranz', 'B2', []],
        ['Nils Ott', 'B2', []],
        ['Finn Reuter', 'B2', []],
        ['Carla Mertens', null, []],
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->service(Config::class);
        if (!$config->isDev()) {
            $output->writeln("<error>seed:demo deletes all data. It runs only while app.env is 'dev'.</error>");

            return Command::FAILURE;
        }

        $groups = VoiceGroup::defaults($config->defaultLocale);
        $members = $this->service(MemberRepository::class);
        Transaction::immediate($this->service(\PDO::class), function () use ($members, $groups): void {
            $members->deleteAll();
            $this->service(VoiceGroupRepository::class)->replaceAll($groups);
        });

        foreach (self::MEMBERS as [$name, $group, $options]) {
            $local = self::localPart($name);
            $emails = [$local . '@' . self::PRIMARY_DOMAIN];
            if ($options['private'] ?? false) {
                $emails[] = $local . '@' . self::PRIVATE_DOMAIN;
            }
            $members->add($name, $emails, $group ?? $groups[0]->key, $options['board'] ?? false, $options['admin'] ?? false);
        }

        $output->writeln(sprintf('<info>Seeded %d voice groups and %d members.</info>', count($groups), count(self::MEMBERS)));

        return Command::SUCCESS;
    }

    /** "Rosa Kühn" becomes "rosa.kuehn". */
    private static function localPart(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/\s+/', '.', $ascii);
    }
}
