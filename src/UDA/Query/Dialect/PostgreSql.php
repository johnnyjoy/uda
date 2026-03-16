<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

namespace UDA\Query\Dialect;

use UDA\SQL\SqlMessage;

/**
 * PostgreSQL dialect implementation.
 */
final class PostgreSql extends OnConflict
{
    public function name(): string
    {
        return 'PostgreSQL';
    }

    public function supportsReturning(): bool
    {
        return true;
    }

    public function supportsWritableCte(): bool
    {
        return true;
    }

    public function supportsRecursiveWritableCte(): bool
    {
        return true;
    }

    public function supportsUpsert(): bool
    {
        return true;
    }

    public function supportsCteMaterializationHints(): bool
    {
        return true;
    }

    public function supportsExplain(): bool
    {
        return true;
    }

    public function supportsExplainAnalyze(): bool
    {
        return true;
    }

    public function buildExplainSql(SqlMessage $sql, bool $analyze): iterable
    {
        $prefix = $analyze ? 'EXPLAIN ANALYZE ' : 'EXPLAIN ';

        yield new SqlMessage($prefix . $sql->getQuery(), $sql->getParams(), $sql->getCacheTables());
    }
}
