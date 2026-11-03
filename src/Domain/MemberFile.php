<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The member list an instance keeps as a CSV file next to its config: one member per row, under a
 * header that names the columns name, email, email2, group, board, and admin in any order. Only
 * name and email are required; other columns are ignored. Spreadsheets save such a file with
 * commas or semicolons, in UTF-8 or Windows-1252, and either works.
 *
 * Parsing checks each row on its own and against the rows above it. Whether a row's member
 * already exists is the importer's to decide.
 */
final readonly class MemberFile
{
    private const COLUMNS = ['name', 'email', 'email2', 'group', 'board', 'admin'];

    private const YES = ['x', '1', 'ja', 'j', 'yes', 'y', 'true', 'wahr'];

    private const NO = ['', '0', 'nein', 'n', 'no', 'false', 'falsch'];

    /**
     * @param list<MemberFileRow> $rows the rows that make a member, in file order
     * @param list<MemberFileProblem> $problems the rows that do not, in file order
     */
    private function __construct(
        public array $rows,
        public array $problems,
    ) {}

    /**
     * @param list<VoiceGroup> $groups the groups a row may name, by key or by label
     */
    public static function parse(string $contents, array $groups): self
    {
        $contents = self::utf8($contents);
        $first = strtok($contents, "\r\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $stream = fopen('php://memory', 'r+');
        assert($stream !== false);
        fwrite($stream, $contents);
        rewind($stream);

        // Blank lines above the header, as a spreadsheet's empty first rows leave them, do not count.
        $line = 0;
        do {
            $header = fgetcsv($stream, null, $delimiter, '"', '');
            ++$line;
        } while ($header !== false && self::isBlank($header));
        $columns = self::columns($header === false ? [] : $header);
        if (!isset($columns['name'], $columns['email'])) {
            fclose($stream);

            return new self([], [new MemberFileProblem($line, MemberFileIssue::HeaderInvalid)]);
        }

        $rows = [];
        $problems = [];
        $seen = [];
        while (($fields = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            ++$line;
            if (self::isBlank($fields)) {
                continue;
            }
            $cell = static fn(string $column): string => isset($columns[$column]) ? trim((string) ($fields[$columns[$column]] ?? '')) : '';
            $row = self::row($line, $cell, $groups);
            if ($row instanceof MemberFileProblem) {
                $problems[] = $row;
                continue;
            }
            $repeated = array_values(array_intersect($row->details->emails, $seen));
            if ($repeated !== []) {
                $problems[] = new MemberFileProblem($line, MemberFileIssue::AddressRepeated, $repeated[0]);
                continue;
            }
            $seen = [...$seen, ...$row->details->emails];
            $rows[] = $row;
        }
        fclose($stream);

        return new self($rows, $problems);
    }

    /**
     * @param \Closure(string): string $cell
     * @param list<VoiceGroup> $groups
     */
    private static function row(int $line, \Closure $cell, array $groups): MemberFileRow|MemberFileProblem
    {
        $details = MemberDetails::parse($cell('name'), $cell('email'), $cell('email2'));
        if ($details instanceof MemberDetailsError) {
            return new MemberFileProblem($line, $details);
        }
        $group = null;
        if ($cell('group') !== '') {
            $group = self::groupKey($cell('group'), $groups);
            if ($group === null) {
                return new MemberFileProblem($line, MemberFileIssue::GroupUnknown, $cell('group'));
            }
        }
        $roles = [];
        foreach (['board', 'admin'] as $role) {
            $value = mb_strtolower($cell($role));
            if (!in_array($value, [...self::YES, ...self::NO], true)) {
                return new MemberFileProblem($line, MemberFileIssue::RoleInvalid, $cell($role));
            }
            $roles[$role] = in_array($value, self::YES, true);
        }

        return new MemberFileRow($details, $group, $roles['board'], $roles['admin']);
    }

    /**
     * @param array<int, string|null> $fields
     */
    private static function isBlank(array $fields): bool
    {
        return trim(implode('', array_map(static fn(?string $field): string => (string) $field, $fields))) === '';
    }

    /**
     * The position of each known column, by its lower-cased name.
     *
     * @param array<int, string|null> $header
     *
     * @return array<string, int>
     */
    private static function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $position => $name) {
            $name = mb_strtolower(trim((string) $name));
            if (in_array($name, self::COLUMNS, true) && !isset($columns[$name])) {
                $columns[$name] = $position;
            }
        }

        return $columns;
    }

    /**
     * @param list<VoiceGroup> $groups
     */
    private static function groupKey(string $value, array $groups): ?string
    {
        $value = mb_strtolower($value);
        foreach ($groups as $group) {
            if (mb_strtolower($group->key) === $value || mb_strtolower($group->label) === $value) {
                return $group->key;
            }
        }

        return null;
    }

    /** The contents as UTF-8 without a byte order mark. Anything that is not UTF-8 is read as Windows-1252. */
    private static function utf8(string $contents): string
    {
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        return mb_check_encoding($contents, 'UTF-8') ? $contents : mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }
}
