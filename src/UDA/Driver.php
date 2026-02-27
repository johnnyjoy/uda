<?php

declare(strict_types=1);

/**
 * @purpose Base execution engine – sole PDO owner and single hot path.
 *
 * This class represents the core driver responsible for establishing a PDO
 * connection, managing the single hot path for statement preparation,
 * binding and execution, handling transactions, and orchestrating cache
 * transparently when enabled. It is the authoritative entry point for all
 * database interactions throughout the library.
 */

namespace UDA;

use UDA\Query\Sql;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use UDA\Cache\Infra;
use UDA\Cache\Statistics;
use UDA\Cache\Setup;
use UDA\Cache;
use UDA\Exception\QueryException;
use UDA\Driver\SqlHelper;
use UDA\Query\DeleteQuery;
use UDA\Query\InsertQuery;
use UDA\Query\SelectQuery;
use UDA\Query\UpsertQuery;
use UDA\Query\UpdateQuery;
use UDA\SQL\SqlMessage;

abstract class Driver
{
    protected PDO $pdo;
    protected string $driverName;
    protected string $connectionName;
    protected array $connectionConfig;
protected ?Cache $cache = null;

/**
 * @purpose Get cache instance (lazy initialization)
 */
public function getCache(): Cache
{
    if ($this->cache === null) {
        throw new QueryException('Caching is not configured for this connection');
    }
    
    return $this->cache;
}

    private ?string $lastSql = null;
    private array $lastParams = [];
    private int $transactionLevel = 0;
    private int $savepointCounter = 0;

    public function __construct(PDO $pdo, string $driverName, array $connectionConfig, string $connectionName, ?Setup $cacheSetup = null)
    {
        $this->pdo = $pdo;
        $this->driverName = $driverName;
        $this->connectionConfig = $connectionConfig;
        $this->connectionName = $connectionName;
        
        // ⭐ LAZY: Only instantiate if caching globally enabled AND configured
        // Initialise cache façade (may be null if no setup provided)
        $this->cache = Cache::fromSetup($connectionName, $cacheSetup);
    }

    public static function fromConfig(string $connectionName, array $connectionConfig, PDO $pdo, ?Setup $cacheSetup = null): self
    {
        return DriverFactory::create(
            (string) ($connectionConfig['driver'] ?? ''),
            $pdo,
            $connectionConfig,
            $connectionName,
            $cacheSetup
        );
    }

    public function exec(string|SqlMessage $sql, array $params = [], ?array $tables = null): int
    {
        $stmt = $this->executeInternal($sql, $params);
        $affected = $stmt->rowCount();
        
        // Touch tables for cache invalidation if affected rows > 0
        if ($affected > 0 && $tables !== null && $this->cache !== null) {
            $this->touchTables($tables);
        }
        
        return $affected;
    }

    public function rows(string|SqlMessage|\UDA\SQL\Sql $sql, array $params = [], ?array $tables = null): array
    {
        // Transparent caching - automatically handled if cache is configured
        if ($this->cache !== null) {
            $normalizedSql = $this->normalizeSqlForCache($sql, $params);
            $cacheKey = $this->generateCacheKey($normalizedSql[0], $normalizedSql[1]);
            
            // Metadata-first approach - check metadata only first
            $meta = $this->cache->getMetadata($cacheKey);
            if ($meta !== null && $this->shouldServeFromCache($meta, $tables)) {
                return $this->cache->getResult($cacheKey);
            }
            
            // Execute if not cached or cache invalid
            $stmt = $this->executeInternal($sql, $params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Store in cache with metadata
            $this->storeInCache($cacheKey, $result, $tables);
            
            return $result;
        }
        
        // No cache configured, execute directly
        $stmt = $this->executeInternal($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function row(string|SqlMessage|\UDA\SQL\Sql $sql, array $params = [], ?array $tables = null): ?array
    {
        // Transparent caching - automatically handled if cache is configured
        if ($this->cache !== null) {
            $normalizedSql = $this->normalizeSqlForCache($sql, $params);
            $cacheKey = $this->generateCacheKey($normalizedSql[0], $normalizedSql[1]);
            
            // Metadata-first approach - check metadata only first
            $meta = $this->cache->getMetadata($cacheKey);
            if ($meta !== null && $this->shouldServeFromCache($meta, $tables)) {
                return $this->cache->getResult($cacheKey);
            }
            
            // Execute if not cached or cache invalid
            $stmt = $this->executeInternal($sql, $params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row === false) {
                $result = null;
            } else {
                if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
                    throw new QueryException('Expected at most one row for row()');
                }
                $result = $row;
            }
            
            // Store in cache with metadata
            $this->storeInCache($cacheKey, $result, $tables);
            
            return $result;
        }
        
        // No cache configured, execute directly
        $stmt = $this->executeInternal($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new QueryException('Expected at most one row for row()');
        }

        return $row;
    }

    private function readThroughCache(string $method, string|SqlMessage|\UDA\SQL\Sql $sql, array $params, ?array $tables): mixed
    {
        $scope = $this->cache(null, $tables);
        // Normalize to raw query string and parameters
        if ($sql instanceof SqlMessage) {
            $query = $sql->getQuery();
            $params = array_merge($sql->getParams(), $params);
        } elseif ($sql instanceof \UDA\SQL\Sql) {
            $query = $sql->sql;
            $params = array_merge($sql->params, $params);
        } else {
            $query = $sql;
        }
        return $scope->{$method}($query, $params, $tables);
    }

    public function value(string|SqlMessage $sql, array $params = [], ?array $tables = null): mixed
    {
        $row = $this->row($sql, $params, $tables);

        if ($row === null) {
            return null;
        }

        if (count($row) !== 1) {
            throw new QueryException('value() requires a single column result');
        }

        return array_values($row)[0];
    }

    public function values(string|SqlMessage $sql, array $params = [], ?array $tables = null): array
    {
        $rows = $this->rows($sql, $params, $tables);
        $values = [];

        foreach ($rows as $row) {
            if ($row === []) {
                continue;
            }

            $values[] = array_values($row)[0];
        }

        return $values;
    }

    public function list(string|SqlMessage $sql, array $params = [], ?array $tables = null): array
    {
        return $this->values($sql, $params, $tables);
    }

    public function each(string|SqlMessage $sql, array|callable $params, callable $fn = null): int
    {
        if (is_callable($params) && $fn === null) {
            $fn = $params;
            $params = [];
        } elseif (!is_array($params)) {
            throw new QueryException('Parameters must be an array');
        }

        if ($fn === null) {
            throw new QueryException('Callable is required for each()');
        }

        $stmt = $this->executeInternal($sql, $params);
        $count = 0;

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fn($row);
            $count++;
        }

        return $count;
    }

    public function transaction(callable $fn): mixed
    {
        $level = $this->transactionLevel;
        $savepoint = null;

        if ($level === 0) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = $this->createSavepointName();
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }

        $this->transactionLevel++;

        try {
            $result = $fn($this);
            $this->transactionLevel--;

            if ($level === 0) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;

            if ($level === 0) {
                $this->pdo->rollBack();
            } elseif ($savepoint !== null) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }

            throw $e;
        }
    }

    public function lastSql(): ?string
    {
        return $this->lastSql;
    }

    public function lastParams(): array
    {
        return $this->lastParams;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    // Cache method removed - cache is now transparent and automatic
    // All caching logic is handled internally in the Driver

    public function touchTables(array $tables): void
    {
        if ($this->cache === null) {
            return;
        }
        // Delegate to the Cache façade which will invalidate entries via its bridge
        $this->cache->touchTables($tables);
    }

    public function getCacheStatistics(): ?Statistics
    {
        return $this->cacheStatistics;
    }

    public function q(string $identifier): string
    {
        return $this->quoteIdentifier($identifier);
    }

public function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string    {        return SqlHelper::orderByAllowed($column, $allowlist, $direction);    }
public function limitOffset(int $limit, int $offset): SqlMessage    {        $sql = SqlHelper::limitOffset($limit, $offset);        return new SqlMessage($sql, ['limit' => $limit, 'offset' => $offset]);    }
        public function inList(array $values, string $hint = 'p'): SqlMessage
    {
        if ($values === []) {
            return new SqlMessage('1=0', []);
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
        return new SqlMessage($sql, $params);
    }

    protected function quoteIdentifier(string $identifier): string
    {
        $identifier = str_replace('"', '""', $identifier);
        return '"' . $identifier . '"';
    }

    protected function createSavepointName(): string
    {
        $this->savepointCounter++;
        return 'uda_sp_' . $this->savepointCounter;
    }

    protected function executeInternal(string|SqlMessage $sql, array $params): PDOStatement
    {
        [$query, $mergedParams] = $this->normalizeSql($sql, $params);
        $this->lastSql = $query;
        $this->lastParams = $mergedParams;

        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($mergedParams);
            return $stmt;
        } catch (PDOException $ex) {
            throw new QueryException('Query execution failed: ' . $ex->getMessage(), 0, $ex);
        }
    }

    protected function normalizeSql(string|SqlMessage $sql, array $params): array
    {
        $query = $sql;

        if ($sql instanceof SqlMessage) {
            $query = $sql->getQuery();
            $params = array_merge($sql->getParams(), $params);
        } elseif ($sql instanceof \UDA\SQL\Sql) {
            $query = $sql->sql;
            $params = array_merge($sql->params, $params);
        }

        $this->ensureNamedParameters($query);
        return [$query, $params];
    }

    protected function ensureNamedParameters(string $query): void
    {
        if (strpos($query, '?') !== false) {
            throw new QueryException('Positional parameters are forbidden in public API');
        }
    }

    public function getDriverName(): string
    {
        return $this->driverName;
    }

    // ----- Query Builder Execution Methods -----
    
    /**
     * Execute a SELECT query and return all rows
     */
    public function selectRows(SelectQuery $query, ?array $tables = null): array
    {
        return $this->rows($query->toSql(), [], $tables);
    }
    
    /**
     * Execute a SELECT query and return a single row
     */
    public function selectRow(SelectQuery $query, ?array $tables = null): ?array
    {
        return $this->row($query->toSql(), [], $tables);
    }
    
    /**
     * Execute a SELECT query and return a single value
     */
    public function selectValue(SelectQuery $query, ?array $tables = null): mixed
    {
        $row = $this->row($query->toSql(), [], $tables);
        if ($row === null) {
            return null;
        }
        if (count($row) !== 1) {
            throw new QueryException('selectValue() requires a single column result');
        }
        return array_values($row)[0];
    }
    
    /**
     * Execute a SELECT query and return values from the first column
     */
    public function selectValues(SelectQuery $query, ?array $tables = null): array
    {
        $rows = $this->rows($query->toSql(), [], $tables);
        $values = [];
        foreach ($rows as $row) {
            if ($row !== []) {
                $values[] = array_values($row)[0];
            }
        }
        return $values;
    }
    
    /**
     * Execute an INSERT query
     */
    public function insertExec(InsertQuery $query): int
    {
        $reflection = new \ReflectionClass($query);
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableName = $tableProperty->getValue($query);
        
        $sql = $query->toSql();
        $affected = $this->exec($sql->sql, $sql->params, $tableName ? [$tableName] : null);
        return $affected;
    }
    
    /**
     * Execute an UPDATE query
     */
    public function updateExec(UpdateQuery $query): int
    {
        $reflection = new \ReflectionClass($query);
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableName = $tableProperty->getValue($query);
        
        $sql = $query->toSql();
        $affected = $this->exec($sql->sql, $sql->params, $tableName ? [$tableName] : null);
        return $affected;
    }
    
    /**
     * Execute a DELETE query
     */
    public function deleteExec(DeleteQuery $query): int
    {
        $reflection = new \ReflectionClass($query);
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableName = $tableProperty->getValue($query);
        
        $sql = $query->toSql();
        $affected = $this->exec($sql->sql, $sql->params, $tableName ? [$tableName] : null);
        return $affected;
    }
    
    /**
     * Execute an UPSERT query
     */
    public function upsertExec(UpsertQuery $query): int
    {
        $reflection = new \ReflectionClass($query);
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableName = $tableProperty->getValue($query);
        
        $sql = $query->toSql();
        $affected = $this->exec($sql->sql, $sql->params, $tableName ? [$tableName] : null);
        return $affected;
    }
    
    public function select(): SelectQuery
    {
        $builder = new SelectQuery();
        $builder->driverInstance = $this;
        $builder->driverName = $this->driverName;
        return $builder;
    }

    public function insert(): InsertQuery
    {
        $builder = new InsertQuery();
        $builder->driverInstance = $this;
        $builder->driverName = $this->driverName;
        return $builder;
    }

    public function update(): UpdateQuery
    {
        $builder = new UpdateQuery();
        $builder->driverInstance = $this;
        $builder->driverName = $this->driverName;
        return $builder;
    }

    public function delete(): DeleteQuery
    {
        $builder = new DeleteQuery();
        $builder->driverInstance = $this;
        $builder->driverName = $this->driverName;
        return $builder;
    }

    public function upsert(): UpsertQuery
    {
        $builder = new UpsertQuery();
        $builder->driverInstance = $this;
        $builder->driverName = $this->driverName;
        return $builder;
    }
    /**
     * Execute a Sql query (for INSERT/UPDATE/DELETE)
     * 
     * @purpose Execute DML queries from Sql value objects
     */
    public function executeSql(Sql $sql, ?array $tables = null): int
    {
        $stmt = $this->executeInternal($sql->sql, $sql->params);
        $affected = $stmt->rowCount();
        
        // Touch tables for cache invalidation if affected rows > 0
        if ($affected > 0 && $tables !== null && $this->cache !== null) {
            $this->touchTables($tables);
        }
        
        return $affected;
    }
    
    /**
     * Query for multiple rows (SELECT)
     * 
     * @purpose Execute SELECT query and return all rows
     */
    public function querySqlRows(Sql $sql): array
    {
        $stmt = $this->executeInternal($sql->sql, $sql->params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Query for a single row (SELECT)
     * 
     * @purpose Execute SELECT query and return one row or null
     */
    public function querySqlRow(Sql $sql): ?array
    {
        $stmt = $this->executeInternal($sql->sql, $sql->params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row === false) {
            return null;
        }
        
        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new QueryException('Expected at most one row');
        }
        
        return $row;
    }
    
    /**
     * Execute a query builder (for DML)
     * 
     * @purpose Fluent API convenience - builds and executes in one call
     */
    public function execute(object $query): int
    {
        if (!method_exists($query, 'toSql')) {
            throw new QueryException('Query must implement toSql() method');
        }
        
        $sql = $query->toSql();
        return $this->executeSql($sql);
    }
    
    /**
     * Execute a query builder and return rows (for SELECT)
     * 
     * @purpose Fluent API convenience - builds and queries in one call
     */
    public function queryRows(object $query): array
    {
        if (!method_exists($query, 'toSql')) {
            throw new QueryException('Query must implement toSql() method');
        }
        
        $sql = $query->toSql();
        return $this->querySqlRows($sql);
    }
    
    /**
     * Execute a query builder and return single row (for SELECT)
     * 
     * @purpose Fluent API convenience - builds and queries in one call
     */
    public function queryRow(object $query): ?array
    {
        if (!method_exists($query, 'toSql')) {
            throw new QueryException('Query must implement toSql() method');
        }
        
        $sql = $query->toSql();
return $this->querySqlRow($sql);
    }
    
    /**
     * Factory method to create driver from connection config
     * 
     * @purpose Domain Master - sole entry into Driver domain
     */
    public static function fromConnection(array $connection, string $connectionName, ?Setup $cacheSetup = null): self
    {
        $driverName = $connection['driver'] ?? '';
        
        if ($driverName === '') {
            throw new \UDA\Exception\ConfigException("Connection '$connectionName' missing driver");
        }
        
        $dsn = self::buildDsn($driverName, $connection['params'] ?? []);
        $user = $connection['user'] ?? null;
        $pass = $connection['pass'] ?? null;
        
        // Merge options
        $options = array_merge([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ], $connection['options'] ?? []);
        
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $ex) {
            throw new \UDA\Exception\ConnectionException("Failed to connect: " . $ex->getMessage(), 0, $ex);
        }
        
        return match ($driverName) {
            'sqlite' => new Driver\SQLite($pdo, $driverName, $connection, $connectionName, $cacheSetup),
            'pgsql', 'postgresql' => new Driver\PostgreSQL($pdo, $driverName, $connection, $connectionName, $cacheSetup),
            'sqlsrv', 'mssql', 'sqlserver' => new Driver\SQLServer($pdo, $driverName, $connection, $connectionName, $cacheSetup),
            'mysql', 'mariadb' => new Driver\MariaDB($pdo, $driverName, $connection, $connectionName, $cacheSetup),
            'dblib' => new Driver\Dblib($pdo, $driverName, $connection, $connectionName, $cacheSetup),
            default => new Driver\GenericDriver($pdo, $driverName, $connection, $connectionName, $cacheSetup),
        };
    }
    
    /**
     * Build DSN from driver name and params
     */
    private static function buildDsn(string $driverName, array $params): string
    {
        return match ($driverName) {
            'sqlite' => $params['file'] ?? '::memory:',
            'pgsql', 'postgresql' => self::buildPgsqlDsn($params),
            'mysql', 'mariadb' => self::buildMysqlDsn($params),
            'sqlsrv', 'mssql' => self::buildSqlsrvDsn($params),
            'dblib' => self::buildDblibDsn($params),
            default => throw new \UDA\Exception\ConfigException("Unknown driver: $driverName"),
        };
    }
    
    private static function buildPgsqlDsn(array $params): string
    {
        $parts = ['pgsql'];
        if (isset($params['host'])) $parts[] = 'host=' . $params['host'];
        if (isset($params['dbname'])) $parts[] = 'dbname=' . $params['dbname'];
        if (isset($params['port'])) $parts[] = 'port=' . $params['port'];
        return implode(';', $parts);
    }
    
    private static function buildMysqlDsn(array $params): string
    {
        $dsn = 'mysql:';
        if (!empty($params['host'])) $dsn .= 'host=' . $params['host'] . ';';
        if (!empty($params['port'])) $dsn .= 'port=' . $params['port'] . ';';
        if (!empty($params['dbname'])) $dsn .= 'dbname=' . $params['dbname'];
        return rtrim($dsn, ';');
    }
    
    private static function buildSqlsrvDsn(array $params): string
    {
        $server = $params['host'] ?? 'localhost';
        $port = isset($params['port']) ? ',' . $params['port'] : '';
        $dsn = "sqlsrv:Server={$server}{$port}";
        if (!empty($params['dbname'])) $dsn .= ";Database={$params['dbname']}";
        return $dsn;
    }
    
    private static function buildDblibDsn(array $params): string
    {
        $host = $params['host'] ?? 'localhost';
        $port = isset($params['port']) ? ':' . $params['port'] : '';
        $dsn = "dblib:host={$host}{$port}";
        if (!empty($params['dbname'])) $dsn .= ";dbname={$params['dbname']}";
        return $dsn;
    }
    
    /**
     * Normalize SQL and parameters for cache key generation
     */
    private function normalizeSqlForCache(string|SqlMessage|\UDA\SQL\Sql $sql, array $params): array
    {
        $query = $sql;
        $mergedParams = $params;

        if ($sql instanceof SqlMessage) {
            $query = $sql->getQuery();
            $mergedParams = array_merge($sql->getParams(), $params);
        } elseif ($sql instanceof \UDA\SQL\Sql) {
            $query = $sql->sql;
            $mergedParams = array_merge($sql->params, $params);
        }

        return [$query, $mergedParams];
    }
    
    /**
     * Generate cache key from SQL and parameters
     */
    private function generateCacheKey(string $sql, array $params): string
    {
        $keyData = [
            'sql' => $sql,
            'params' => $params
        ];
        return md5(serialize($keyData));
    }
    
    /**
     * Determine if result should be served from cache based on metadata and table timestamps
     */
    private function shouldServeFromCache(object $meta, ?array $tables): bool
    {
        // Check TTL
        if (isset($meta->expiresAt) && time() > $meta->expiresAt) {
            return false;
        }
        
        // If no tables specified, serve from cache
        if ($tables === null || empty($tables)) {
            return true;
        }
        
        // Check table timestamps for invalidation
        if ($this->cache !== null) {
            foreach ($tables as $table) {
                $tableMtime = $this->cache->getTableMtime($this->connectionName, $table);
                if (isset($meta->createdAt) && $tableMtime > $meta->createdAt) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Store result in cache with metadata
     */
    private function storeInCache(string $cacheKey, mixed $result, ?array $tables): void
    {
        if ($this->cache === null) {
            return;
        }
        
        $meta = (object)[
            'createdAt' => time(),
            'expiresAt' => time() + 300, // Default 5 minute TTL
            'tables' => $tables ?? []
        ];
        
        $this->cache->set($cacheKey, $meta, $result, 300);
    }
}
