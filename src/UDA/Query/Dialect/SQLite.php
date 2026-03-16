<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Query\Dialect
 */

namespace UDA\Query\Dialect;

use UDA\Exception\QueryException;
use UDA\SQL\SqlMessage;

/**
 * SQLite dialect implementation leveraging modern UPSERT.
 */
final class SQLite extends OnConflict
{
    public function name(): string
    {
        return 'SQLite';
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

    public function buildExplainSql(SqlMessage $sql, bool $analyze): iterable
    {
        if ($analyze) {
            throw new QueryException('SQLite dialect does not support EXPLAIN ANALYZE statements.');
        }

        yield new SqlMessage('EXPLAIN QUERY PLAN ' . $sql->getQuery(), $sql->getParams(), $sql->getCacheTables());
    }
}
