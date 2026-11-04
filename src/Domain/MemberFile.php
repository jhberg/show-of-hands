<?php

declare(strict_types=1);

namespace ShowOfHands\Domain;

/**
 * The member list an instance keeps as a CSV file next to its config: one member per row, under a
 * header that names the columns name, email, email2, group, board, admin, and inactive in any
 * order. Only name and email are required; other columns are ignored. Spreadsheets save such a
 * file with commas or semicolons, in UTF-8, Windows-1252, or Mac Roman (Excel for Mac's
 * "CSV (Macintosh)"), and each works.
 *
 * Parsing checks each row on its own and against the rows above it. Whether a row's member
 * already exists is the importer's to decide.
 */
final readonly class MemberFile
{
    private const COLUMNS = ['name', 'email', 'email2', 'group', 'board', 'admin', 'inactive'];

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
        // fgetcsv() ends lines only at \n, and old Mac files end them with a bare \r.
        $contents = (string) preg_replace('/\r\n?/', "\n", self::utf8($contents));
        $first = strtok($contents, "\n") ?: '';
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
        $flags = [];
        foreach (['board', 'admin', 'inactive'] as $flag) {
            $value = mb_strtolower($cell($flag));
            if (!in_array($value, [...self::YES, ...self::NO], true)) {
                return new MemberFileProblem($line, MemberFileIssue::FlagInvalid, $cell($flag));
            }
            $flags[$flag] = in_array($value, self::YES, true);
        }

        return new MemberFileRow($details, $group, $flags['board'], $flags['admin'], !$flags['inactive']);
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

    /**
     * The contents as UTF-8 without a byte order mark. Anything that is not UTF-8 is Windows-1252 or
     * Mac Roman. The two place accented letters on different bytes: read in the wrong one, "Bäcker"
     * turns into "BŠcker" or "B‰cker". Bare \r line endings mark Mac Roman. Otherwise Mac Roman wins
     * only with fewer characters that do not belong in a member list. Those are all but ASCII, the
     * letters À to ÿ, and the quotes, dashes, and euro sign of Windows-1252. A ‘ inside a word counts
     * as strange too, since there it is Mac Roman's ë ("No‘l"). The bias keeps a
     * Windows-1252 file as it was read before Mac Roman joined, while a Mac Roman file with umlauts
     * reads as "BŠcker" in Windows-1252 and so goes to Mac Roman.
     */
    private static function utf8(string $contents): string
    {
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }
        $windows = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        // mbstring knows no Mac Roman, intl does.
        $mac = \UConverter::transcode($contents, 'UTF-8', 'macintosh');
        if (str_contains($contents, "\r") && !str_contains($contents, "\n")) {
            return $mac;
        }
        $strange = static fn(string $text): int => (int) preg_match_all('/[^\x{00}-\x{7F}\x{A0}\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{FF}‘’“”„–€]|(?<=\p{L})‘(?=\p{L})/u', $text);

        return $strange($mac) < $strange($windows) ? $mac : $windows;
    }
}
