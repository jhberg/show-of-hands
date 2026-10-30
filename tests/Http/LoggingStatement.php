<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

/**
 * A prepared statement that reports each execution, with its bound values, to LoggingPdo.
 */
final class LoggingStatement extends \PDOStatement
{
    /** @var array<int|string, mixed> */
    private array $bound = [];

    protected function __construct() {}

    public function bindValue(int|string $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;

        return parent::bindValue($param, $value, $type);
    }

    /**
     * @param array<int|string, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        LoggingPdo::record($this->queryString, array_values($params ?? $this->bound));
        $this->bound = [];

        return parent::execute($params);
    }
}
