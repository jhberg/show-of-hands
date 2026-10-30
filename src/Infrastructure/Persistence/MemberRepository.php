<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

use ShowOfHands\Domain\EmailAddress;
use ShowOfHands\Domain\Member;
use ShowOfHands\Infrastructure\Database\Transaction;

/**
 * Members and their sign-in addresses.
 */
final readonly class MemberRepository
{
    private const COLUMNS = 'm.id, m.name, m.voice_group_key, m.is_board, m.is_admin, m.locale';

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
     * Every member, in voice group order, then by name.
     *
     * @return list<Member>
     */
    public function all(string $locale): array
    {
        return array_column($this->allWithEmails($locale), 'member');
    }

    /**
     * Every member with their addresses, in voice group order, then by name.
     *
     * @return list<array{member: Member, emails: list<string>}>
     */
    public function allWithEmails(string $locale): array
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
            ?: (int) $collator->compare($a['member']->name, $b['member']->name));

        return array_map(static fn(array $entry): array => ['member' => $entry['member'], 'emails' => $entry['emails']], $members);
    }

    public function isEmpty(): bool
    {
        $statement = $this->pdo->query('SELECT EXISTS (SELECT 1 FROM members)');

        return $statement === false || $statement->fetchColumn() === 0;
    }

    /**
     * @param list<string> $emails normalized addresses, the first one first
     *
     * @throws DuplicateEmailException when another member already uses one of the addresses
     */
    public function add(string $name, array $emails, ?string $voiceGroupKey, bool $isBoard, bool $isAdmin): int
    {
        if ($emails === [] || count($emails) > EmailAddress::MAX_PER_MEMBER) {
            throw new \InvalidArgumentException('A member needs one or two addresses.');
        }
        return Transaction::immediate($this->pdo, function () use ($name, $emails, $voiceGroupKey, $isBoard, $isAdmin): int {
            $taken = $this->pdo->prepare('SELECT email FROM member_emails WHERE email = ?');
            foreach ($emails as $email) {
                $taken->execute([$email]);
                if ($taken->fetchColumn() !== false) {
                    throw new DuplicateEmailException($email);
                }
            }
            $this->pdo->prepare('INSERT INTO members (name, voice_group_key, is_board, is_admin) VALUES (?, ?, ?, ?)')
                ->execute([$name, $voiceGroupKey, (int) $isBoard, (int) $isAdmin]);
            $id = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO member_emails (email, member_id, position) VALUES (?, ?, ?)');
            foreach ($emails as $position => $email) {
                $insert->execute([$email, $id, $position]);
            }

            return $id;
        });
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

    /**
     * @param array<mixed> $row
     */
    private static function member(array $row): Member
    {
        return new Member(
            id: Row::int($row, 'id'),
            name: Row::string($row, 'name'),
            voiceGroupKey: Row::nullableString($row, 'voice_group_key'),
            isBoard: Row::int($row, 'is_board') === 1,
            isAdmin: Row::int($row, 'is_admin') === 1,
            locale: Row::nullableString($row, 'locale'),
        );
    }
}
