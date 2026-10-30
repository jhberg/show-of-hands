<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Console;

use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Database\Transaction;
use ShowOfHands\Infrastructure\I18n\DayFormat;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Replaces all data with the sample choir of the reference mockup: 25 invented members in nine
 * voice groups, some with a second address and with board or admin roles, and the mockup's polls
 * with their ballots. Dates are relative to today, so the sample never goes stale.
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

    /**
     * Sample texts in both languages. {date} is a day and month.
     */
    private const TEXTS = [
        'de' => [
            'first_note' => 'Klara hat in der Probe am {date} ihr Vorsingen. Die Abstimmung bleibt bis zur Besprechung und Stimmabgabe nach dem Vorsingen offen.',
            'second_note' => 'Mila hat in derselben Probe ihr Vorsingen. Bitte beide Abstimmungen getrennt aufrufen, jede hat einen eigenen Link.',
            'question' => 'Darf Jakob beim Konzert am {date} im Bass mitsingen, obwohl seine Probezeit noch läuft?',
            'exception_note' => 'Jakob ist seit drei Wochen in der Probezeit. Die Chorleitung möchte ihn beim Konzert dabei haben, weil im Bass zwei Personen fehlen.',
        ],
        'en' => [
            'first_note' => 'Klara auditions at the rehearsal on {date}. The poll stays open until the discussion and the vote after the audition.',
            'second_note' => 'Mila auditions at the same rehearsal. Please open both polls separately, each has its own link.',
            'question' => 'May Jakob sing bass at the concert on {date}, although his probation is still running?',
            'exception_note' => 'Jakob has been on probation for three weeks. The director wants him at the concert because the bass section is two people short.',
        ],
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
        $polls = $this->service(PollRepository::class);
        Transaction::immediate($this->service(\PDO::class), function () use ($members, $polls, $groups): void {
            $polls->deleteAll();
            $members->deleteAll();
            $this->service(VoiceGroupRepository::class)->replaceAll($groups);
        });

        $ids = [];
        foreach (self::MEMBERS as [$name, $group, $options]) {
            $local = self::localPart($name);
            $emails = [$local . '@' . self::PRIMARY_DOMAIN];
            if ($options['private'] ?? false) {
                $emails[] = $local . '@' . self::PRIVATE_DOMAIN;
            }
            $ids[] = $members->add($name, $emails, $group ?? $groups[0]->key, $options['board'] ?? false, $options['admin'] ?? false);
        }

        $pollCount = $this->seedPolls($config, $polls, $ids);

        $output->writeln(sprintf('<info>Seeded %d voice groups, %d members, and %d polls.</info>', count($groups), count(self::MEMBERS), $pollCount));

        return Command::SUCCESS;
    }

    /**
     * The mockup's polls: two admissions at the next audition, an exception, and a draft.
     *
     * @param list<int> $ids member ids in the order of MEMBERS
     */
    private function seedPolls(Config $config, PollRepository $polls, array $ids): int
    {
        $zone = new \DateTimeZone($config->timezone);
        $today = $this->service(Clock::class)->now()->setTimezone($zone)->setTime(0, 0);
        $day = static fn(int $offset): \DateTimeImmutable => $today->modify("{$offset} days");
        $stamp = static fn(int $offset, int $hour, int $minute): string => $day($offset)->setTime($hour, $minute)
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
        // The next rehearsal: the first Tuesday at least two days ahead.
        $audition = $day(2);
        while ($audition->format('N') !== '2') {
            $audition = $audition->modify('+1 day');
        }
        $texts = self::TEXTS[$config->defaultLocale];
        $dayAndMonth = static fn(\DateTimeImmutable $date): string => DayFormat::format($date, 'dMMMM', $config->defaultLocale);

        $first = $polls->add(PollKind::Admission, 'Klara Wendt', null, 'S2', $audition->format('Y-m-d'), strtr($texts['first_note'], ['{date}' => $dayAndMonth($audition)]), PollStatus::Open, $stamp(-3, 20, 0), $stamp(-3, 20, 10));
        $second = $polls->add(PollKind::Admission, 'Mila Sandberg', null, 'A2', $audition->format('Y-m-d'), $texts['second_note'], PollStatus::Open, $stamp(-3, 20, 5), $stamp(-3, 20, 12));
        $exception = $polls->add(PollKind::Exception, 'Jakob Lenz', strtr($texts['question'], ['{date}' => $dayAndMonth($day(12))]), 'B1', $day(9)->format('Y-m-d'), $texts['exception_note'], PollStatus::Open, $stamp(-1, 20, 50), $stamp(-1, 21, 0));
        $draft = $polls->add(PollKind::Admission, 'Jakob Lenz', null, 'B1', $day(16)->format('Y-m-d'), '', PollStatus::Draft, $stamp(0, 9, 0));

        // Member numbers count from 1 in the order of MEMBERS, as in the mockup.
        $ballots = $this->service(BallotRepository::class);
        foreach ([
            [$first, [1, 2, 8, 15, 20, 21], [6, 9, 17]],
            [$second, [1, 14], [19]],
            [$exception, [14, 20], []],
        ] as [$poll, $yes, $no]) {
            foreach ($yes as $number) {
                $ballots->cast($poll, $ids[$number - 1], Choice::Yes);
            }
            foreach ($no as $number) {
                $ballots->cast($poll, $ids[$number - 1], Choice::No);
            }
        }

        return count([$first, $second, $exception, $draft]);
    }

    /** "Rosa Kühn" becomes "rosa.kuehn". */
    private static function localPart(string $name): string
    {
        $ascii = strtr(mb_strtolower($name), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return (string) preg_replace('/\s+/', '.', $ascii);
    }
}
