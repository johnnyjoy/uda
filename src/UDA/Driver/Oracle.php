<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Driver
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/driver/oracle
 * @since 1.0.0
 */

/*
 * Purpose: Oracle-specific driver implementation providing DSN construction,
 * quoting, pagination, and savepoint behavior for PDO_OCI connections.
 */

namespace UDA\Driver;

use PDO;
use PDOException;
use UDA\Driver as BaseDriver;
use UDA\Exception\ConfigException;
use UDA\Exception\QueryException;
use UDA\Query\Sql as BuilderSql;
use UDA\SQL\SqlMessage;

final class Oracle extends BaseDriver
{
    private const RETURNING_BUFFER_LENGTH = 4000;

    protected ?string $dbtype = 'oci';

    protected function buildDsn(array $params): string
    {
        if (isset($params['dbname'])) {
            return 'oci:dbname=' . $params['dbname'];
        }

        $host = $params['host'] ?? null;
        $service = $params['service'] ?? ($params['sid'] ?? null);
        $port = (int)($params['port'] ?? 1521);

        if ($host === null || $service === null) {
            throw new ConfigException('Oracle configuration requires host and service (or sid)');
        }

        return sprintf('oci:dbname=//%s:%d/%s', $host, $port, $service);
    }

    protected function quoteIdentifier(string $identifier): string
    {
        $clean = strtoupper(trim($identifier));
        $escaped = str_replace('"', '""', $clean);

        return '"' . $escaped . '"';
    }

    public function limitOffset(int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new QueryException('LIMIT/OFFSET must be non-negative');
        }

        return sprintf('OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $offset, $limit);
    }

    protected function onConnect(): void
    {
        // Oracle session configuration (NLS, timezone) can be added here later
    }

    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $tableHints = $tables ?? ($sql instanceof BuilderSql ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        $columns = $message->getReturningColumns();

        if ($columns === []) {
            return parent::returning($message, $normalized, $tableHints);
        }
        $valueSets = $message->getValuePlaceholders();

        if ($valueSets === [] || count($valueSets) <= 1) {
            $rows = $this->runOracleReturningStatement($message->getQuery(), $normalized, $columns);

            if ($rows !== [] && $tableHints !== []) {
                $this->cache->touchTables($tableHints);
            }

            return $rows;
        }

        $insertTable = $message->getInsertTable();
        $insertColumns = $message->getInsertColumns();

        if ($insertTable === null || $insertColumns === []) {
            throw new QueryException('Oracle multi-row returning requires insert metadata.');
        }

        $quotedColumns = array_map(fn (string $col): string => $this->q(strtoupper($col)), $insertColumns);
        $prefix = sprintf('INSERT INTO %s (%s)', $this->q($insertTable), implode(', ', $quotedColumns));

        $rows = [];
        foreach ($valueSets as $rowPlaceholders) {
            $singleSql = $prefix . ' VALUES (' . implode(', ', $rowPlaceholders) . ')';
            $rowParams = $this->extractParamsForPlaceholders($normalized, $rowPlaceholders);
            $rows = array_merge($rows, $this->runOracleReturningStatement($singleSql, $rowParams, $columns));
        }

        if ($rows !== [] && $tableHints !== []) {
            $this->cache->touchTables($tableHints);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $normalized
     * @param array<int,string>   $placeholders
     *
     * @return array<string,mixed>
     */
    private function extractParamsForPlaceholders(array $normalized, array $placeholders): array
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
     */
    private function runOracleReturningStatement(string $baseQuery, array $params, array $columns): array
    {
        $quotedColumns = array_map(fn (string $col): string => $this->q(strtoupper($col)), $columns);
        $placeholders = [];
        $outValues = [];

        foreach ($columns as $idx => $column) {
            $placeholder = ':uda_return_' . $idx;
            $placeholders[] = $placeholder;
            $outValues[$idx] = '';
        }

        $query = $baseQuery . ' RETURNING ' . implode(', ', $quotedColumns) . ' INTO ' . implode(', ', $placeholders);

        try {
            $stmt = $this->acquirePreparedStatement($query);

            foreach ($params as $name => $value) {
                $paramName = str_starts_with((string) $name, ':') ? (string) $name : ':' . $name;
                $stmt->bindValue($paramName, $value);
            }

            foreach ($placeholders as $idx => $placeholder) {
                $stmt->bindParam(
                    $placeholder,
                    $outValues[$idx],
                    PDO::PARAM_STR | PDO::PARAM_INPUT_OUTPUT,
                    self::RETURNING_BUFFER_LENGTH
                );
            }

            $stmt->execute();
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
