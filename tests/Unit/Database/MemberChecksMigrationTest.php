<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Domain\ColorMode;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\I18n\Locales;
use ShowOfHands\Tests\TempDirectory;

/**
 * Migration 0010 rebuilds the members table to add CHECKs on locale and color_mode.
 */
final class MemberChecksMigrationTest extends TestCase
{
    use TempDirectory;

    private const MIGRATION = '0010_member_checks.sql';

    private string $dir;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        $this->pdo = ConnectionFactory::open($this->dir . '/app.sqlite');
    }

    private function migrateUpTo(string $last): void
    {
        $all = array_values(array_filter(scandir(self::migrationsDir()) ?: [], static fn(string $name): bool => str_ends_with($name, '.sql')));
        $release = array_values(array_filter($all, static fn(string $name): bool => strcmp($name, $last) <= 0));
        (new Migrator($this->pdo, self::migrationsDir(), $this->dir, $release))->migrate();
    }

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll();
    }

    public function testKeepsTheMembersAndWhatReferencesThem(): void
    {
        $this->migrateUpTo('0009_member_color_mode.sql');
        $this->pdo->exec(<<<'SQL'
            INSERT INTO voice_groups (key, label, position) VALUES ('S1', 'Soprano 1', 1);
            INSERT INTO members (name, voice_group_key, is_board, is_admin, locale, display_name, color_mode)
                VALUES ('Ada Example', 'S1', 1, 1, 'en', 'Ada', 'dark'),
                       ('Ben Example', 'S1', 0, 0, 'fr', NULL, 'sepia'),
                       ('Cem Example', NULL, 0, 0, NULL, NULL, NULL),
                       ('Dan Example', 'S1', 0, 0, 'de', NULL, 'system');
            UPDATE members SET is_active = 0 WHERE name = 'Cem Example';
            INSERT INTO member_emails (email, member_id, position) VALUES ('ada@example.org', 1, 0), ('ada@example.net', 1, 1), ('ben@example.org', 2, 0);
            INSERT INTO polls (kind, subject_name, voice_group_key, status, created_at) VALUES ('admission', 'Robin Example', 'S1', 'open', '2026-01-01T00:00:00Z');
            INSERT INTO participation (poll_id, member_id) VALUES (1, 1);
            INSERT INTO delegations (poll_id, from_member_id, to_member_id) VALUES (1, 2, 1);
            DELETE FROM members WHERE name = 'Dan Example';
            SQL);

        $this->migrateUpTo(self::MIGRATION);

        self::assertSame([
            ['id' => 1, 'name' => 'Ada Example', 'voice_group_key' => 'S1', 'is_board' => 1, 'is_admin' => 1, 'locale' => 'en', 'is_active' => 1, 'display_name' => 'Ada', 'color_mode' => 'dark'],
            ['id' => 2, 'name' => 'Ben Example', 'voice_group_key' => 'S1', 'is_board' => 0, 'is_admin' => 0, 'locale' => null, 'is_active' => 1, 'display_name' => null, 'color_mode' => null],
            ['id' => 3, 'name' => 'Cem Example', 'voice_group_key' => null, 'is_board' => 0, 'is_admin' => 0, 'locale' => null, 'is_active' => 0, 'display_name' => null, 'color_mode' => null],
        ], $this->rows('SELECT * FROM members ORDER BY id'));
        self::assertCount(3, $this->rows('SELECT * FROM member_emails'));
        self::assertCount(1, $this->rows('SELECT * FROM participation'));
        self::assertCount(1, $this->rows('SELECT * FROM delegations'));
        self::assertSame([], $this->rows('PRAGMA foreign_key_check'));

        $this->pdo->exec("INSERT INTO members (name) VALUES ('Eva Example')");
        self::assertSame(5, (int) $this->pdo->lastInsertId(), 'The removed member\'s id 4 is not given out again.');

        $this->pdo->exec('DELETE FROM members WHERE id = 1');
        self::assertSame([], $this->rows('SELECT * FROM member_emails WHERE member_id = 1'), 'Removing a member still removes their addresses.');
        self::assertSame([], $this->rows('SELECT * FROM delegations'));
    }

    /** Against the current schema, so a new locale fails here until a migration adds it. */
    public function testAcceptsOnlyTheLocalesAndColourModesTheAppWrites(): void
    {
        (new Migrator($this->pdo, self::migrationsDir(), $this->dir))->migrate();
        $insert = $this->pdo->prepare('INSERT INTO members (name, locale, color_mode) VALUES (?, ?, ?)');
        foreach (Locales::SUPPORTED as $locale) {
            foreach (ColorMode::cases() as $mode) {
                $insert->execute(['Kim Example', $locale, $mode->value]);
            }
        }
        $insert->execute(['Kim Example', null, null]);

        foreach ([['xx', null], [null, 'sepia'], ['', null], [null, '']] as [$locale, $mode]) {
            try {
                $insert->execute(['Lou Example', $locale, $mode]);
                self::fail('The members table accepted locale ' . var_export($locale, true) . ' and colour mode ' . var_export($mode, true) . '.');
            } catch (\PDOException $e) {
                self::assertStringContainsString('CHECK constraint failed', $e->getMessage());
            }
        }
    }
}
