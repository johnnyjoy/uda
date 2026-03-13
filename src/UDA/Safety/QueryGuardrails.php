<?php

declare(strict_types=1);

namespace UDA\Safety;

use UDA\Exception\QuerySafetyException;
use UDA\SQL\SqlMessage;

final class QueryGuardrails
{
    /**
     * Statement types that participate in the limit-on-writes guardrail.
     *
     * @var list<string>
     */
    private const LIMIT_TYPES = ['insert', 'update', 'delete', 'upsert'];

    public static function validate(SqlMessage $sql, GuardrailConfig $config, string $operation): void
    {
        if (!$config->enabled) {
            return;
        }

        if ($sql->isUnsafe() && !$config->productionMode) {
            return;
        }

        $statementType = $sql->getStatementType();

        if ($config->truncateBlocked && self::isTruncateStatement($sql)) {
            throw new QuerySafetyException('truncate_blocked');
        }

        if ($config->requiresWhere($statementType) && !$sql->hasWhereClause()) {
            throw new QuerySafetyException(sprintf('%s_missing_where', $statementType));
        }

        if (self::requiresLimitOnWrites($statementType, $config, $sql) && !$sql->hasLimitClause()) {
            throw new QuerySafetyException(sprintf('%s_missing_limit', $statementType));
        }
    }

    private static function requiresLimitOnWrites(string $statementType, GuardrailConfig $config, SqlMessage $sql): bool
    {
        if (!in_array($statementType, self::LIMIT_TYPES, true)) {
            return false;
        }

        return $config->requiresLimitOnWrites(self::resolvePrimaryTable($sql));
    }

    private static function resolvePrimaryTable(SqlMessage $sql): ?string
    {
        $insertTable = $sql->getInsertTable();

        if (is_string($insertTable) && $insertTable !== '') {
            return $insertTable;
        }

        $tables = $sql->getCacheTables();

        return $tables[0] ?? null;
    }

    private static function isTruncateStatement(SqlMessage $sql): bool
    {
        $query = ltrim(strtolower($sql->getQuery()));

        return str_starts_with($query, 'truncate');
    }
}
