<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

/**
 * A connection that records every statement it runs, with the values bound to it.
 */
final class LoggingPdo extends \PDO
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    private static array $log = [];

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [LoggingStatement::class, []]);
    }

    /**
     * @param list<mixed> $params
     */
    public static function record(string $sql, array $params = []): void
    {
        self::$log[] = ['sql' => $sql, 'params' => $params];
    }

    /**
     * The statements since the last reset, oldest first.
     *
     * @return list<array{sql: string, params: list<mixed>}>
     */
    public static function entries(): array
    {
        return self::$log;
    }

    public static function reset(): void
    {
        self::$log = [];
    }

    public function exec(string $statement): int|false
    {
        self::record($statement);

        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        self::record($query);

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
