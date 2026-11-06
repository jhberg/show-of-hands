<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\ColorMode;
use ShowOfHands\Domain\EmailAddress;
use ShowOfHands\Domain\Member;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Members and their sign-in addresses.
 */
final readonly class MemberRepository
{
    private const COLUMNS = 'm.id, m.name, m.voice_group_key, m.is_board, m.is_admin, m.locale, m.is_active, m.display_name, m.color_mode';

    public function __construct(private \PDO $pdo) {}

    public function find(int $id): ?Member
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM members m WHERE m.id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? self::member($row) : null;
    }

    /**
     * @return array{Member, bool}|null the member and whether the address is their second one
     */
    public function findByEmail(string $email): ?array
    {
        $normalized = EmailAddress::normalize($email);
        if ($normalized === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ', e.position FROM member_emails e JOIN members m ON m.id = e.member_id WHERE e.email = ?');
        $statement->execute([$normalized]);
        $row = $statement->fetch();

        return is_array($row) ? [self::member($row), Row::int($row, 'position') > 0] : null;
    }

    /**
     * The member's addresses, the first one first.
     *
     * @return list<string>
     */
    public function emailsOf(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT email FROM member_emails WHERE member_id = ? ORDER BY position');
        $statement->execute([$id]);

        $emails = [];
        foreach ($statement->fetchAll() as $row) {
            assert(is_array($row));
            $emails[] = Row::string($row, 'email');
        }

        return $emails;
    }

    /**
     * Every member, in voice group order, then by shown name, as the name lists show them.
     *
     * @return list<Member>
     */
    public function all(string $locale): array
    {
        return array_column($this->sorted($locale, static fn(Member $member): string => $member->shownName()), 'member');
    }

    /**
     * The eligible voters: every active member, in voice group order, then by shown name.
     *
     * @return list<Member>
     */
    public function eligible(string $locale): array
    {
        return Member::eligibleOf($this->all($locale));
    }

    /**
     * Every member with their addresses, in voice group order, then by full name, as the admin
     * area's member list shows them.
     *
     * @return list<array{member: Member, emails: list<string>}>
     */
    public function allWithEmails(string $locale): array
    {
        return $this->sorted($locale, static fn(Member $member): string => $member->name);
    }

    /**
     * @param \Closure(Member): string $name the name the order follows within a voice group
     *
     * @return list<array{member: Member, emails: list<string>}>
     */
    private function sorted(string $locale, \Closure $name): array
    {
        $statement = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ', e.email, coalesce(g.position, 999999) AS group_position
             FROM members m
             LEFT JOIN voice_groups g ON g.key = m.voice_group_key
             LEFT JOIN member_emails e ON e.member_id = m.id
             ORDER BY m.id, e.position',
        );
        $members = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            assert(is_array($row));
            $id = Row::int($row, 'id');
            $members[$id] ??= ['member' => self::member($row), 'emails' => [], 'position' => Row::int($row, 'group_position')];
            $email = Row::nullableString($row, 'email');
            if ($email !== null) {
                $members[$id]['emails'][] = $email;
            }
        }
        $collator = new \Collator($locale);
        usort($members, static fn(array $a, array $b): int => $a['position'] <=> $b['position']
            ?: (int) $collator->compare($name($a['member']), $name($b['member'])));

        return array_map(static fn(array $entry): array => ['member' => $entry['member'], 'emails' => $entry['emails']], $members);
    }

    public function count(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM members');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    public function countEligible(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM members WHERE is_active = 1');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * Sets the member active or inactive. The caller checks first, inside its transaction, that
     * the change alters no poll counted with them.
     */
    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare('UPDATE members SET is_active = ? WHERE id = ?')->execute([(int) $active, $id]);
    }

    public function setDisplayName(int $id, ?string $displayName): void
    {
        $this->pdo->prepare('UPDATE members SET display_name = ? WHERE id = ?')->execute([$displayName, $id]);
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * @param list<string> $emails normalized addresses, the first one first
     *
     * @throws DuplicateEmailException when another member already uses one of the addresses
     */
    public function add(string $name, array $emails, ?string $voiceGroupKey, bool $isBoard, bool $isAdmin, ?string $displayName = null): int
    {
        self::assertAddressCount($emails);

        return Transaction::immediate($this->pdo, function () use ($name, $emails, $voiceGroupKey, $isBoard, $isAdmin, $displayName): int {
            $this->assertAddressesFree($emails, null);

            return $this->insert($name, $emails, $voiceGroupKey, $isBoard, $isAdmin, true, $displayName);
        });
    }

    /**
     * Adds a member inside the caller's transaction, after the caller checked that no member uses
     * the addresses.
     *
     * @param list<string> $emails normalized addresses, the first one first
     */
    public function insert(string $name, array $emails, ?string $voiceGroupKey, bool $isBoard, bool $isAdmin, bool $isActive, ?string $displayName): int
    {
        self::assertAddressCount($emails);
        $this->pdo->prepare('INSERT INTO members (name, display_name, voice_group_key, is_board, is_admin, is_active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $displayName, $voiceGroupKey, (int) $isBoard, (int) $isAdmin, (int) $isActive]);
        $id = (int) $this->pdo->lastInsertId();
        $this->insertEmails($id, $emails);

        return $id;
    }

    /**
     * Replaces the member's name, display name, addresses, group, and roles.
     *
     * While a poll is open, or was closed today and may still be reopened, no address is added or
     * replaced: its owner could sign in as the member and read or change their vote. Removing
     * one stays allowed.
     *
     * @param list<string> $emails normalized addresses, the first one first
     *
     * @return bool false when the member no longer exists
     *
     * @throws DuplicateEmailException when another member already uses one of the addresses
     * @throws NewAddressTooSoonException when an address is new while a poll is in reach
     */
    public function update(int $id, string $name, array $emails, ?string $voiceGroupKey, bool $isBoard, bool $isAdmin, ?string $displayName, \DateTimeImmutable $now, \DateTimeZone $zone): bool
    {
        self::assertAddressCount($emails);

        return Transaction::immediate($this->pdo, function () use ($id, $name, $emails, $voiceGroupKey, $isBoard, $isAdmin, $displayName, $now, $zone): bool {
            $update = $this->pdo->prepare('UPDATE members SET name = ?, display_name = ?, voice_group_key = ?, is_board = ?, is_admin = ? WHERE id = ?');
            $update->execute([$name, $displayName, $voiceGroupKey, (int) $isBoard, (int) $isAdmin, $id]);
            if ($update->rowCount() === 0) {
                return false;
            }
            if (array_diff($emails, $this->emailsOf($id)) !== []) {
                $open = PollsTakenPart::hold($this->pdo, null, $now, $zone);
                if ($open !== null) {
                    throw new NewAddressTooSoonException($id, open: $open);
                }
            }
            $this->assertAddressesFree($emails, $id);
            $this->pdo->prepare('DELETE FROM member_emails WHERE member_id = ?')->execute([$id]);
            $this->insertEmails($id, $emails);

            return true;
        });
    }

    /**
     * Deletes the member with their addresses. Their turnout marks and the delegations to and
     * from them go with them. The caller removes their ballots first, inside one transaction.
     */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
    }

    /**
     * Deletes every member and resets the id counter. Only the demo seed does this.
     */
    public function deleteAll(): void
    {
        $this->pdo->exec('DELETE FROM members');
        $this->pdo->exec("DELETE FROM sqlite_sequence WHERE name = 'members'");
    }

    public function setLocale(int $id, string $locale): void
    {
        $this->pdo->prepare('UPDATE members SET locale = ? WHERE id = ?')->execute([$locale, $id]);
    }

    public function setColorMode(int $id, ColorMode $mode): void
    {
        $this->pdo->prepare('UPDATE members SET color_mode = ? WHERE id = ?')->execute([$mode->value, $id]);
    }

    /**
     * @param list<string> $emails
     */
    private static function assertAddressCount(array $emails): void
    {
        if ($emails === [] || count($emails) > EmailAddress::MAX_PER_MEMBER) {
            throw new \InvalidArgumentException('A member needs one or two addresses.');
        }
    }

    /**
     * @param list<string> $emails
     *
     * @throws DuplicateEmailException
     */
    private function assertAddressesFree(array $emails, ?int $exceptMemberId): void
    {
        foreach ($emails as $email) {
            $owner = $this->findByEmail($email);
            if ($owner !== null && $owner[0]->id !== $exceptMemberId) {
                throw new DuplicateEmailException($email);
            }
        }
    }

    /**
     * @param list<string> $emails
     */
    private function insertEmails(int $memberId, array $emails): void
    {
        $insert = $this->pdo->prepare('INSERT INTO member_emails (email, member_id, position) VALUES (?, ?, ?)');
        foreach ($emails as $position => $email) {
            $insert->execute([$email, $memberId, $position]);
        }
    }

    /**
     * @param array<mixed> $row
     */
    private static function member(array $row): Member
    {
        $colorMode = Row::nullableString($row, 'color_mode');

        return new Member(
            id: Row::int($row, 'id'),
            name: Row::string($row, 'name'),
            voiceGroupKey: Row::nullableString($row, 'voice_group_key'),
            isBoard: Row::int($row, 'is_board') === 1,
            isAdmin: Row::int($row, 'is_admin') === 1,
            locale: Row::nullableString($row, 'locale'),
            isActive: Row::int($row, 'is_active') === 1,
            displayName: Row::nullableString($row, 'display_name'),
            colorMode: $colorMode === null ? null : ColorMode::from($colorMode),
        );
    }
}
