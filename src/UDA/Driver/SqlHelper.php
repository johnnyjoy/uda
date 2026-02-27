<?php

declare(strict_types=1);

/** @purpose SQL utility helper - handles quoting, LIMIT/OFFSET, ORDER BY, IN clauses */

namespace UDA\Driver;

use UDA\Exception\QueryException;

final class SqlHelper
{
/**
 * Generate LIMIT/OFFSET clause
 */
public static function limitOffset(int $limit, int $offset): string
{
    return "LIMIT {$limit} OFFSET {$offset}";
}

/**
 * Create ORDER BY clause with allowlist validation
 */
public static function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string
{
    $direction = strtoupper($direction);
    
    if (!in_array($direction, ['ASC', 'DESC'], true)) {
        throw new QueryException('Order direction must be ASC or DESC');
    }
    
    $plainColumn = trim($column, '"');
    if (!isset($allowlist[$column]) && !isset($allowlist[$plainColumn])) {
        throw new QueryException('Column not allowed in ORDER BY: ' . $column);
    }
    
    return "ORDER BY {$column} {$direction}";
}

/**
 * Generate IN clause with named parameters
 */
public static function inList(array $values, string $hint = 'p'): array
{
    if ($values === []) {
        return ['1=0', []];
    }
    
    $fragments = [];
    $params = [];
    $safeHint = preg_replace('/[^a-zA-Z0-9_]/', '_', $hint);
    
    foreach (array_values($values) as $index => $value) {
        $key = sprintf('%s_%d', $safeHint, $index);
        $fragments[] = ":{$key}";
        $params[$key] = $value;
    }
    
    $sql = 'IN (' . implode(', ', $fragments) . ')';
    return [$sql, $params];
}
}
