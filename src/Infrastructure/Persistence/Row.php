<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Persistence;

/**
 * Typed reads from a PDO result row. SQLite returns integers as int and text as string,
 * so a value of another type means the schema and the query disagree.
 */
final class Row
{
    /**
     * @param array<mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        throw new \UnexpectedValueException("Column {$column} is not an integer.");
    }

    /**
     * @param array<mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("Column {$column} is not text.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $row
     */
    public static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::string($row, $column);
    }
}
