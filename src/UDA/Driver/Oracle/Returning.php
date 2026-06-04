<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver\Oracle
 * @license MIT
 * @since 1.0.0
 */

namespace UDA\Driver\Oracle;

use PDO;
use PDOException;
use PDOStatement;
use UDA\Driver;
use UDA\Exception\QueryException;
use UDA\SQL\SqlMessage;

/*
 * Purpose: RETURNING INTO execution orchestration (no direct prepare/execute).
 *
 * SQL fragments from \UDA\Driver\Oracle; binds OUTPUT parameters via UDA\Driver execute callback.
 */

/**
 * RETURNING INTO output-bind path for Oracle connections.
 */
final class Returning
{
    public function __construct(private readonly Driver $driver)
    {
    }

    /**
     * @param array<string,mixed> $normalized
     *
     * @return array<int,array<string,mixed>>
     */
    public function run(SqlMessage $message, array $normalized): array
    {
        $columns = $message->getReturningColumns();
        $valueSets = $message->getValuePlaceholders();

        if ($valueSets === [] || count($valueSets) <= 1) {
            return $this->runStatement($message->getQuery(), $normalized, $columns);
        }

        $insertTable = $message->getInsertTable();
        $insertColumns = $message->getInsertColumns();

        if ($insertTable === null || $insertColumns === []) {
            throw new QueryException('Oracle multi-row returning requires insert metadata.');
        }

        $quotedColumns = [];

        foreach ($insertColumns as $col) {
            $quotedColumns[] = $this->driver->q(strtoupper($col));
        }

        $prefix = sprintf(
            'INSERT INTO %s (%s)',
            $this->driver->q($insertTable),
            implode(', ', $quotedColumns),
        );

        $rows = [];
        foreach ($valueSets as $rowPlaceholders) {
            $singleSql = $prefix . ' VALUES (' . implode(', ', $rowPlaceholders) . ')';
            $rowParams = $this->paramsForPlaceholders($normalized, $rowPlaceholders);
            $rows = array_merge($rows, $this->runStatement($singleSql, $rowParams, $columns));
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<int,string>   $placeholders
     *
     * @return array<string,mixed>
     */
    private function paramsForPlaceholders(array $normalized, array $placeholders): array
    {
        $subset = [];
        foreach ($placeholders as $placeholder) {
            $name = ltrim($placeholder, ':');
            if (array_key_exists($name, $normalized)) {
                $subset[$name] = $normalized[$name];
            }
        }

        return $subset;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<int,string>   $columns
     *
     * @return array<int,array<string,mixed>>
     */
    private function runStatement(string $baseQuery, array $params, array $columns): array
    {
        $placeholders = [];
        $outValues = [];

        foreach ($columns as $idx => $column) {
            $placeholder = ':uda_return_' . $idx;
            $placeholders[] = $placeholder;
            $outValues[$idx] = '';
        }

        $query = \UDA\Driver\Oracle::returningIntoSql($baseQuery, $columns, $placeholders);

        try {
            $stmt = $this->driver->executeInternal(
                $query,
                $params,
                function (PDOStatement $stmt, array $params) use ($placeholders, &$outValues): void {
                    foreach ($params as $name => $value) {
                        $paramName = str_starts_with((string) $name, ':') ? (string) $name : ':' . $name;
                        $stmt->bindValue($paramName, $value);
                    }

                    foreach ($placeholders as $idx => $placeholder) {
                        $stmt->bindParam(
                            $placeholder,
                            $outValues[$idx],
                            PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT,
                            \UDA\Driver\Oracle::returningBufferLength(),
                        );
                    }
                },
            );

            $affected = $stmt->rowCount();
        } catch (PDOException $ex) {
            throw new QueryException('Oracle returning() execution failed: ' . $ex->getMessage(), 0, $ex);
        }

        $stmt->closeCursor();

        $normalizedValues = [];

        foreach ($outValues as $value) {
            if (is_string($value)) {
                $value = rtrim($value);
                if ($value === '') {
                    $value = null;
                } elseif (ctype_digit($value)) {
                    $value = (int) $value;
                } elseif (is_numeric($value)) {
                    $value = (float) $value;
                }
            }
            $normalizedValues[] = $value;
        }

        if ($affected === 0) {
            return [];
        }

        if ($affected > 1) {
            throw new QueryException('Oracle returning() expects at most one row per statement.');
        }

        $normalizedColumns = array_map('strtolower', $columns);

        return [array_combine($normalizedColumns, $normalizedValues) ?: []];
    }
}
