<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Setup;

use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Config\BootstrapAdmin;
use ShowOfHands\Infrastructure\Database\Transaction;
use ShowOfHands\Infrastructure\Persistence\DuplicateEmailException;
use ShowOfHands\Infrastructure\Persistence\MemberImporter;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;

/**
 * Fills a new installation: the default voice groups, the first admin from bootstrap_admin, and
 * the members of the member file, if it is there.
 *
 * It acts only while the member list is empty. Admins cannot delete themselves, so once the first
 * member exists, it never runs again and never brings back a removed group or member. A member
 * file that comes later waits for an admin, who sees what it adds before it runs.
 */
final readonly class FirstStart
{
    public function __construct(
        private \PDO $pdo,
        private MemberRepository $members,
        private VoiceGroupRepository $groups,
        private MemberImporter $importer,
        private Clock $clock,
        private ?BootstrapAdmin $admin,
        private string $defaultLocale,
    ) {}

    public function run(): void
    {
        if (!$this->members->isEmpty()) {
            return;
        }
        $this->fill();
        $this->importer->importOnce($this->clock->now());
    }

    private function fill(): void
    {
        if ($this->groups->all() === []) {
            $this->seedGroups();
        }
        if ($this->admin === null) {
            return;
        }
        $group = $this->admin->group !== null && $this->groups->exists($this->admin->group) ? $this->admin->group : null;
        try {
            $this->members->add($this->admin->name, [$this->admin->email], $group, true, true);
        } catch (DuplicateEmailException) {
            // A parallel first request added the admin in the meantime.
        }
    }

    private function seedGroups(): void
    {
        Transaction::immediate($this->pdo, function (): void {
            // A parallel first request may have seeded them while this one waited for the lock.
            if ($this->groups->all() === []) {
                $this->groups->replaceAll(VoiceGroup::defaults($this->defaultLocale));
            }
        });
    }
}
