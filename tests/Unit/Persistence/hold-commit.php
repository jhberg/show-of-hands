<?php

declare(strict_types=1);

// Runs one write in its own process and holds the write lock just before it commits.
// ConcurrencyTest starts it: php hold-commit.php <database> close <poll>
//                                                         cast <poll> <member> <choice>
//                                                         delegate <poll> <from> <to>

use ShowOfHands\Domain\BallotToken;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\RollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Tests\Unit\Persistence\CommitHoldingPdo;
use ShowOfHands\Tests\Unit\Persistence\ConcurrencyTest;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $database, $action, $poll] = $argv;
$pdo = ConnectionFactory::open($database, CommitHoldingPdo::class);
$ballots = new BallotRepository($pdo, new BallotToken(ConcurrencyTest::SECRET));
$delegations = new DelegationRepository($pdo, $ballots, new SettingsRepository($pdo));

match ($action) {
    'close' => (new PollCloser($pdo, new PollRepository($pdo), $ballots, $delegations, new MemberRepository($pdo), new RollRepository($pdo)))
        ->close((int) $poll, new DateTimeImmutable(ConcurrencyTest::FIRST_CLOSE)),
    'cast' => $ballots->cast((int) $poll, (int) $argv[4], Choice::from($argv[5])),
    'delegate' => $delegations->delegate((int) $poll, (int) $argv[4], (int) $argv[5]),
    default => throw new InvalidArgumentException("Unknown action {$action}."),
};
