<?php

declare(strict_types=1);

namespace UDA;

/**
 * @package UDA
 * @subpackage Core
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/core/driver
 * @since 1.0.0
 */

/*
 * Purpose: Base execution engine and sole owner of PDO connections in UDA.
 */

use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use UDA\Cache\InMemoryTableWriteTracker;
use UDA\Cache\Serializer\Serializer as CacheSerializer;
use UDA\Cache\Setup as CacheSetup;
use UDA\Cache\Store\ArrayStore;
use UDA\Cache\Store\CacheStoreInterface;
use UDA\Cache\Store\MemcachedStore;
use UDA\Cache\Store\RedisStore;
use UDA\Driver\PreparedStatementCache;
use UDA\Exception\ConfigException;
use UDA\Exception\ConnectionException;
use UDA\Exception\NotSupportedException;
use UDA\Exception\QueryException;
use UDA\Query\Abs as QueryBuilderBase;
use UDA\Query\Delete;
use UDA\Query\Dialect\Db2;
use UDA\Query\Dialect\Dialect;
use UDA\Query\Dialect\MariaDb;
use UDA\Query\Dialect\Oracle as OracleDialect;
use UDA\Query\Dialect\PostgreSql;
use UDA\Query\Dialect\SQLite as SqliteDialect;
use UDA\Query\Dialect\SqlServer as SqlServerDialect;
use UDA\Query\Dialect\Sybase;
use UDA\Query\Insert;
use UDA\Query\Select;
use UDA\Query\Sql as BuilderSql;
use UDA\Query\Update;
use UDA\Query\Upsert;
use UDA\SQL\SqlMessage;

abstract class Driver
{
    /**
     * The PDO instance (sole owner).
     *
     * @var PDO
     */
    protected PDO $pdo;

    /**
     * Backend driver name (normalized; e.g. 'sqlserver', 'pgsql', 'mariadb').
     *
     * NOTE: This is not necessarily the PDO driver name. Example: SQLServer backend
     * might use PDO 'sqlsrv' or PDO 'dblib' depending on platform/config.
     *
     * @var ?string
     */
    protected ?string $dbtype = null;

    /**
     * Connection name (config key).
     *
     * @var string
     */
    protected string $connection = 'default';

    /**
     * Raw configuration for this connection.
     *
     * @var array<string,mixed>
     */
    protected array $config = [];

    /**
     * Connection-scoped cache controller.
     */
    protected Cache $cache;

    /** @var ?Dialect Lazily-instantiated SQL dialect */
    private ?Dialect $dialectInstance = null;

    /**
     * Last executed SQL (debug).
     *
     * @var string|null
     */
    private ?string $lastSql = null;

    /**
     * Last executed params (debug).
     *
     * @var array<string,mixed>
     */
    private array $lastParams = [];

    /**
     * Nested transaction depth.
     *
     * @var int
     */
    private int $transactionLevel = 0;

    /**
     * Savepoint counter for nested transactions.
     *
     * @var int
     */
    protected int $savepointCounter = 0;

    private PreparedStatementCache $statementCache;

    private string $connectionHash;
    private bool $lastStatementCacheHit = false;

    /**
     * Protected constructor for concrete drivers.
     */
    protected function __construct(array $config, ?string $connection = null)
    {
        $this->connection = $connection ?? '';
        $this->config = $config;
        $this->dbtype = $config['driver'] ?? $this->dbtype;

        $dsn = $this->buildDsn($config['params'] ?? []);
        [$user, $pass] = self::resolveCredentials($config);
        $options = $this->resolvePdoOptions();

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new ConnectionException('Failed to connect to database: ' . $e->getMessage(), 0, $e);
        }

        $this->runInitSql($this->pdo, $config);
        $this->onConnect();

        $this->cache = Cache::fromSetup($this->connection, $this->buildCacheSetup($config));
        $limit = (int)($this->config['statement_cache_limit'] ?? 500);
        $this->statementCache = new PreparedStatementCache($limit);
        $this->connectionHash = (string) spl_object_id($this);
    }

    /**
     * Called immediately after driver construction.
     *
     * Override in concrete drivers to set session-specific options
     * (e.g., timezone, date format, connection encoding).
     */
    abstract protected function onConnect(): void;

    // ----- Query Builder Factories -----

    public function select(): Select
    {
        $builder = new Select();
        $this->bindQueryBuilder($builder);

        return $builder;
    }

    public function insert(): Insert
    {
        $builder = new Insert();
        $this->bindQueryBuilder($builder);

        return $builder;
    }

    public function update(): Update
    {
        $builder = new Update();
        $this->bindQueryBuilder($builder);

        return $builder;
    }

    public function delete(): Delete
    {
        $builder = new Delete();
        $this->bindQueryBuilder($builder);

        return $builder;
    }

    public function upsert(): Upsert
    {
        $builder = new Upsert();
        $this->bindQueryBuilder($builder);

        return $builder;
    }

    protected function bindQueryBuilder(QueryBuilderBase $builder): void
    {
        $builder->driverInstance = $this;

        if (property_exists($builder, 'driverName')) {
            $builder->driverName = $this->dbtype ?? '';
        }

        if (method_exists($builder, 'bindDialect')) {
            $builder->bindDialect($this->getDialectInstance());
        }
    }

    private function getDialectInstance(): Dialect
    {
        if ($this->dialectInstance !== null) {
            return $this->dialectInstance;
        }

        $backend = strtolower($this->dbtype ?? '');

        $this->dialectInstance = match ($backend) {
            'pgsql', 'postgres', 'postgresql' => new PostgreSql(),
            'sqlite' => new SqliteDialect(),
            'mysql', 'mariadb' => new MariaDb(),
            'sqlsrv', 'sqlserver' => new SqlServerDialect(),
            'dblib', 'sybase' => new Sybase(),
            'oci', 'oracle' => new OracleDialect(),
            'db2' => new Db2(),
            default => throw new QueryException('No SQL dialect available for backend: ' . ($backend !== '' ? $backend : 'unknown')),
        };

        return $this->dialectInstance;
    }

    // ----- Query Builder Execution (default SQL dialect) -----

    public function selectRows(Select $query): array
    {
        $sql = $query->toSql();

        return $this->rows($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function selectRow(Select $query): ?array
    {
        $sql = $query->toSql();

        return $this->row($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function selectValue(Select $query): mixed
    {
        $sql = $query->toSql();

        return $this->value($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function selectValues(Select $query): array
    {
        $sql = $query->toSql();

        return $this->values($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function selectList(Select $query): array
    {
        $sql = $query->toSql();

        return $this->list($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function selectValueSql(SqlMessage $sql): mixed
    {
        return $this->value($sql);
    }

    public function insertExec(Insert $query): int
    {
        $sql = $query->toSql();

        return $this->exec($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function updateExec(Update $query): int
    {
        $sql = $query->toSql();

        return $this->exec($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function deleteExec(Delete $query): int
    {
        $sql = $query->toSql();

        return $this->exec($this->toSqlMessage($sql), [], $sql->getCacheTables());
    }

    public function upsertExec(Upsert $query): int
    {
        throw new NotSupportedException(static::class . ' does not support UPSERT operations.');
    }

    /**
     * Backend-specific identifier quoting. Defaults to ANSI double quotes.
     */
    protected function quoteIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace('"', '""', $clean);

        return '"' . $escaped . '"';
    }

    /**
     * Normalize allowlist column names.
     */
    protected function resolveAllowedColumn(string $column, array $allowlist): ?string
    {
        if (isset($allowlist[$column])) {
            return $column;
        }

        if (in_array($column, $allowlist, true)) {
            return $column;
        }

        $stripped = $this->stripIdentifierQuotes($column);

        if (isset($allowlist[$stripped])) {
            return $stripped;
        }

        if (in_array($stripped, $allowlist, true)) {
            return $stripped;
        }

        return null;
    }

    protected function stripIdentifierQuotes(string $identifier): string
    {
        return trim($identifier, "`\"[]");
    }


    /**
     * Quote an identifier according to backend rules.
     */
    public function q(string $identifier): string
    {
        return $this->quoteIdentifier($identifier);
    }

    /**
     * ORDER BY helper respecting allowlist rules.
     */
    public function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string
    {
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new QueryException('Order direction must be ASC or DESC');
        }

        $allowed = $this->resolveAllowedColumn($column, $allowlist);

        if ($allowed === null) {
            throw new QueryException('Column not allowed in ORDER BY: ' . $column);
        }

        return sprintf('ORDER BY %s %s', $this->q($allowed), $direction);
    }

    /**
     * LIMIT/OFFSET fragment.
     */
    public function limitOffset(int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new QueryException('LIMIT/OFFSET must be non-negative');
        }

        return sprintf('LIMIT %d OFFSET %d', $limit, $offset);
    }

    /**
     * Named-parameter IN clause helper.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    public function inList(array $values, string $hint = 'p'): array
    {
        if ($values === []) {
            return ['1=0', []];
        }

        $safeHint = preg_replace('/[^a-zA-Z0-9_]/', '_', $hint);
        $params = [];
        $placeholders = [];

        foreach (array_values($values) as $index => $value) {
            $key = sprintf('%s_%d', $safeHint, $index);
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }

        return ['IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /**
     * Domain entrypoint: connect using Config by connection name.
     *
     * This is the only correct way to enter the Driver domain.
     *
     * @param string $connectionName Connection key in config.
     *
     * @return static Concrete driver instance.
     *
     * @throws ConfigException     If config missing or invalid.
     * @throws ConnectionException If PDO connection fails.
     */
    public static function connect(?string $connection = null): static
    {
        $config = Config::connection($connection);

        $dbtype = $config['driver'] ?? '';

        $class = match($dbtype) {
            'pgsql', 'postgresql' => \UDA\Driver\PostgreSQL::class,
            'mysql', 'mariadb' => \UDA\Driver\MariaDB::class,
            'sqlsrv', 'sqlserver' => \UDA\Driver\SQLServer::class,
            'dblib', 'sybase' => \UDA\Driver\Dblib::class,
            'sqlite' => \UDA\Driver\SQLite::class,
            'oci', 'oracle' => \UDA\Driver\Oracle::class,
            default => throw new ConfigException("Unsupported database driver type: {$dbtype}"),
        };

        return new $class($config, $connection);
    }

    /**
     * Resolve credentials from config.
     *
     * NOTE: {env:VAR} resolution belongs to Config.
     *
     * @param array<string,mixed> $conn Connection config.
     *
     * @return array{0:string|null,1:string|null}
     */
    private static function resolveCredentials(array $conn): array
    {
        $user = isset($conn['user']) ? (string)$conn['user'] : null;
        $pass = isset($conn['pass']) ? (string)$conn['pass'] : null;

        return [$user, $pass];
    }

    /**
     * Resolve PDO options.
     *
     * Baseline defaults are enforced here; config can override/extend.
     * PDO-driver-specific options may be applied here if needed.
     *
     * @param string              $pdoDriver PDO driver name ('pgsql', 'mysql', 'sqlsrv', 'dblib', 'sqlite').
     * @param array<string,mixed> $conn      Connection config.
     *
     * @return array<int,mixed>
     */
    protected function resolvePdoOptions(): array
    {
        /** @var array<int,mixed> $defaults */
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $opt = $this->config['options'] ?? [];

        if (!is_array($opt)) {
            $opt = [];
        }

        return array_replace($defaults, $opt);
    }

    /**
     * Execute init_sql statements.
     *
     * @param PDO                 $pdo  PDO instance.
     * @param array<string,mixed> $conn Connection config.
     *
     * @return void
     */
    protected function runInitSql(PDO $pdo, array $conn): void
    {
        $init = $this->config['init_sql'] ?? null;

        if (!is_array($init)) {
            return;
        }

        foreach ($init as $stmt) {
            if (!is_string($stmt)) {
                continue;
            }
            $stmt = trim($stmt);

            if (empty($stmt)) {
                continue;
            }
            $pdo->exec($stmt);
        }
    }

    /**
     * Execute a DML statement and return affected row count.
     *
     * @param string|SqlMessage|BuilderSql $sql    SQL statement, SqlMessage, or builder Sql.
     * @param array<string,mixed>          $params Named parameters.
     * @param array<string>|null           $tables Optional table names for cache invalidation.
     *
     * @return int
     *
     * @throws QueryException
     */
    public function exec(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): int
    {
        $tableHints = $tables ?? ($sql instanceof BuilderSql ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);
        $stmt = $this->executeInternal($message, $normalized);
        $affected = $stmt->rowCount();

        if ($affected > 0 && $tableHints !== []) {
            $this->cache->touchTables($tableHints);
        }

        $stmt->closeCursor();

        return $affected;
    }

    /**
     * Execute a DML statement that emits RETURNING/OUTPUT rows.
     */
    public function returning(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $tableHints = $tables ?? ($sql instanceof BuilderSql ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);
        $stmt = $this->executeInternal($message, $normalized);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $affected = $stmt->rowCount();

        if ($affected > 0 && $tableHints !== []) {
            $this->cache->touchTables($tableHints);
        }

        $stmt->closeCursor();

        return $rows;
    }

    /**
     * @param string|SqlMessage|BuilderSql $sql
     * @return array<int,array<string,mixed>>
     */
    public function explain(string|SqlMessage|BuilderSql $sql): array
    {
        return $this->runExplain($sql, false);
    }

    /**
     * @param string|SqlMessage|BuilderSql $sql
     * @return array<int,array<string,mixed>>
     */
    public function explainAnalyze(string|SqlMessage|BuilderSql $sql): array
    {
        return $this->runExplain($sql, true);
    }

    /**
     * Execute a SELECT statement and return all rows.
     *
     * @param string|SqlMessage   $sql    SQL statement or SqlMessage.
     * @param array<string,mixed> $params Named parameters.
     * @param array<string>|null  $tables Optional cache hint tables.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws QueryException
     */
    public function rows(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $tableHints = $tables ?? ($sql instanceof BuilderSql ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            function () use ($message, $normalized): array {
                $stmt = $this->executeInternal($message, $normalized);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $stmt->closeCursor();

                return $rows;
            }
        );
    }

    /**
     * Execute a SELECT statement and return a single row.
     *
     * @param string|SqlMessage|BuilderSql $sql    SQL statement, SqlMessage, or builder Sql.
     * @param array<string,mixed>          $params Named parameters.
     * @param array<string>|null           $tables Optional cache hint tables.
     *
     * @return array<string,mixed>|null
     *
     * @throws QueryException
     */
    public function row(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): ?array
    {
        $tableHints = $tables ?? ($sql instanceof BuilderSql ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            fn () => $this->rowNoCache($message, $normalized)
        );
    }

    /**
     * Execute a SELECT statement and return a single value.
     *
     * @param string|SqlMessage|BuilderSql $sql    SQL statement, SqlMessage, or builder Sql.
     * @param array<string,mixed>          $params Named parameters.
     * @param array<string>|null           $tables Optional cache hint tables.
     *
     * @return mixed
     *
     * @throws QueryException
     */
    public function value(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): mixed
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

    /**
     * Return the first column from all rows.
     */
    public function values(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        $rows = $this->rows($sql, $params, $tables);
        $result = [];

        foreach ($rows as $row) {
            $result[] = array_values($row)[0] ?? null;
        }

        return $result;
    }

    /**
     * Alias for values().
     */
    public function list(string|SqlMessage|BuilderSql $sql, array $params = [], ?array $tables = null): array
    {
        return $this->values($sql, $params, $tables);
    }

    /**
     * Iterate over rows with a callback.
     */
    public function each(string|SqlMessage|BuilderSql $sql, array|callable $params, callable $fn = null, ?array $tables = null): int
    {
        if ($sql instanceof BuilderSql) {
            $tables = $tables ?? $sql->getCacheTables();
            $sql = $this->toSqlMessage($sql);
        }

        if ($fn === null) {
            if (!is_callable($params)) {
                throw new QueryException('each() requires a callback');
            }
            $fn = $params;
            $params = [];
        } elseif (!is_array($params)) {
            throw new QueryException('each() parameters must be provided as an array when callback is supplied');
        }

        $rows = $this->rows($sql, is_array($params) ? $params : [], $tables);

        foreach ($rows as $row) {
            $fn($row);
        }

        return count($rows);
    }

    /**
     * Internal hot path: normalize, prepare, execute.
     *
     * This is the only method allowed to call PDO::prepare() and PDOStatement::execute().
     *
     * @param string|SqlMessage   $sql    SQL statement or SqlMessage.
     * @param array<string,mixed> $params Named parameters.
     *
     * @return PDOStatement
     *
     * @throws QueryException
     */
    protected function executeInternal(string|SqlMessage $sql, array $params): PDOStatement
    {
        [$query, $mergedParams] = $this->normalizeSql($sql, $params);

        $this->lastSql = $query;
        $this->lastParams = $mergedParams;

        $stmt = $this->acquirePreparedStatement($query);

        try {
            $stmt->execute($mergedParams);
        } catch (PDOException $ex) {
            throw new QueryException('Query execution failed: ' . $ex->getMessage(), 0, $ex);
        }

        return $stmt;
    }

    /**
     * Normalize SQL inputs to a raw query string and merged parameters.
     *
     * @param string|SqlMessage   $sql    SQL input.
     * @param array<string,mixed> $params Additional parameters.
     *
     * @return array{0:string,1:array<string,mixed>}
     *
     * @throws QueryException If positional parameters are detected.
     */
    protected function normalizeSql(string|SqlMessage $sql, array $params): array
    {
        $query = $sql;

        if ($sql instanceof SqlMessage) {
            $query = $sql->getQuery();
            $params = array_merge($sql->getParams(), $params);
        }

        if (!is_string($query)) {
            throw new QueryException('SQL must be a string or SqlMessage');
        }

        if (strpos($query, '?') !== false) {
            throw new QueryException('Positional parameters are forbidden in public API');
        }

        return [$query, $params];
    }

    /**
     * Convert input into a SqlMessage while preserving merged parameters.
     *
     * @param string|SqlMessage|BuilderSql $sql SQL input from callers.
     *
     * @return array{0:SqlMessage,1:array<string,mixed>}
     */
    protected function normalizeToSqlMessage(string|SqlMessage|BuilderSql $sql, array $params): array
    {
        if ($sql instanceof BuilderSql) {
            $sql = $this->toSqlMessage($sql);
        }

        $cacheTables = [];
        $returningColumns = [];
        $insertTable = null;
        $insertColumns = [];
        $valuePlaceholders = [];

        if ($sql instanceof SqlMessage) {
            $cacheTables = $sql->getCacheTables();
            $returningColumns = $sql->getReturningColumns();
            $insertTable = $sql->getInsertTable();
            $insertColumns = $sql->getInsertColumns();
            $valuePlaceholders = $sql->getValuePlaceholders();

            if ($params === []) {
                return [$sql, $sql->getParams()];
            }
        }

        [$query, $bound] = $this->normalizeSql($sql, $params);

        return [
            new SqlMessage($query, $bound, $cacheTables, $returningColumns, $insertTable, $insertColumns, $valuePlaceholders),
            $bound,
        ];
    }

    protected function runExplain(string|SqlMessage|BuilderSql $sql, bool $analyze): array
    {
        [$message] = $this->normalizeToSqlMessage($sql, []);
        $dialect = $this->getDialectInstance();

        if ($analyze && !$dialect->supportsExplainAnalyze()) {
            throw new QueryException($dialect->name() . ' dialect does not support EXPLAIN ANALYZE statements.');
        }

        if (!$analyze && !$dialect->supportsExplain()) {
            throw new QueryException($dialect->name() . ' dialect does not support EXPLAIN statements.');
        }

        $statements = $dialect->buildExplainSql($message, $analyze);

        return $this->executeExplainPlan($statements);
    }

    /**
     * @param iterable<int,SqlMessage> $statements
     * @return array<int,array<string,mixed>>
     */
    protected function executeExplainPlan(iterable $statements): array
    {
        $queue = is_array($statements) ? array_values($statements) : iterator_to_array($statements, false);
        $result = [];
        $index = -1;

        try {
            foreach ($queue as $idx => $statement) {
                $index = $idx;

                if (!$statement instanceof SqlMessage) {
                    throw new QueryException('Dialect::buildExplainSql must yield SqlMessage instances.');
                }

                $stmt = $this->executeInternal($statement, $statement->getParams());
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $stmt->closeCursor();

                if ($rows !== []) {
                    $result = $rows;
                }
            }
        } catch (Throwable $e) {
            for ($i = $index + 1; $i < count($queue); $i++) {
                $statement = $queue[$i];

                if (!$statement instanceof SqlMessage) {
                    continue;
                }

                try {
                    $stmt = $this->executeInternal($statement, $statement->getParams());
                    $stmt->closeCursor();
                } catch (Throwable $ignored) {
                    // Swallow cleanup errors to surface original failure
                }
            }

            throw $e;
        }

        return $result;
    }

    protected function acquirePreparedStatement(string $query): PDOStatement
    {
        $key = $this->statementCacheKey($query);
        $stmt = $this->statementCache->get($key);

        if ($stmt === null) {
            $this->lastStatementCacheHit = false;
            try {
                $stmt = $this->pdo->prepare($query);
            } catch (PDOException $ex) {
                throw new QueryException('Failed to prepare statement: ' . $ex->getMessage(), 0, $ex);
            }
            $this->statementCache->put($key, $stmt);
        } else {
            $this->lastStatementCacheHit = true;
            $stmt->closeCursor();
        }

        return $stmt;
    }

    private function statementCacheKey(string $query): string
    {
        $dialect = strtolower($this->getDialectInstance()->name());

        return $dialect . ':' . $this->connectionHash . ':' . $query;
    }

    /**
     * Execute without cache semantics, enforcing single-row constraints.
     */
    protected function rowNoCache(string|SqlMessage $sql, array $params): ?array
    {
        $stmt = $this->executeInternal($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $stmt->closeCursor();
            return null;
        }

        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            $stmt->closeCursor();
            throw new QueryException('row() expects at most one row');
        }

        $stmt->closeCursor();

        return $row;
    }

    /**
     * Execute a callback within a database transaction.
     *
     * Nested transactions are implemented using SAVEPOINT.
     *
     * @param callable(self): mixed $fn Callback to execute within the transaction.
     *
     * @return mixed
     *
     * @throws Throwable Re-throws anything from callback after rollback.
     */
    public function transaction(callable $fn): mixed
    {
        $level = $this->transactionLevel;
        $savepoint = null;

        if ($level === 0) {
            $this->pdo->beginTransaction();
        } else {
            $savepoint = $this->createSavepointName();
            $savepointSql = $this->savepointSql($savepoint);

            if ($savepointSql !== null) {
                $this->pdo->exec($savepointSql);
            }
        }

        $this->transactionLevel++;

        try {
            $result = $fn($this);
            $this->transactionLevel--;

            if ($level === 0) {
                $this->pdo->commit();
            } elseif ($savepoint !== null) {
                $releaseSql = $this->releaseSavepointSql($savepoint);

                if ($releaseSql !== null) {
                    $this->pdo->exec($releaseSql);
                }
            }

            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;

            if ($level === 0) {
                $this->pdo->rollBack();
            } elseif ($savepoint !== null) {
                $rollbackSql = $this->rollbackSavepointSql($savepoint);

                if ($rollbackSql !== null) {
                    $this->pdo->exec($rollbackSql);
                }

                $releaseSql = $this->releaseSavepointSql($savepoint);

                if ($releaseSql !== null) {
                    $this->pdo->exec($releaseSql);
                }
            }

            throw $e;
        }
    }

    /**
     * Create a savepoint name for nested transactions.
     *
     * Concrete backends may override naming rules if required.
     *
     * @return string
     */
    protected function createSavepointName(): string
    {
        $this->savepointCounter++;

        return 'uda_sp_' . $this->savepointCounter;
    }

    protected function savepointSql(string $name): ?string
    {
        return "SAVEPOINT {$name}";
    }

    protected function releaseSavepointSql(string $name): ?string
    {
        return "RELEASE SAVEPOINT {$name}";
    }

    protected function rollbackSavepointSql(string $name): ?string
    {
        return "ROLLBACK TO SAVEPOINT {$name}";
    }

    /**
     * Helper to convert builder Sql objects into SqlMessage instances.
     */
    protected function toSqlMessage(BuilderSql $sql): SqlMessage
    {
        return new SqlMessage(
            $sql->getQuery(),
            $sql->getParams(),
            $sql->getCacheTables(),
            $sql->getReturningColumns(),
            $sql->getInsertTable(),
            $sql->getInsertColumns(),
            $sql->getValuePlaceholders(),
            $sql->getStatementType(),
            $sql->hasWhereClause(),
            $sql->hasLimitClause(),
            $sql->isUnsafe()
        );
    }

    /**
     * Shared read path that routes through cache when enabled.
     */
    private function executeRead(SqlMessage $sql, array $tables, callable $executor): mixed
    {
        if (!isset($this->cache)) {
            return $executor();
        }

        return $this->cache->read($sql, $tables, $executor, fn (Throwable $e) => $this->isTransient($e));
    }

    protected function isTransient(Throwable $e): bool
    {
        return false;
    }

    private function buildCacheSetup(array $config): ?CacheSetup
    {
        $cacheConfig = $config['cache'] ?? null;

        if (!is_array($cacheConfig)) {
            return null;
        }

        $store = $this->buildCacheStore($cacheConfig['store'] ?? []);

        if ($store === null) {
            return null;
        }

        $tracker = new InMemoryTableWriteTracker();
        $serializer = new CacheSerializer($cacheConfig['serializer'] ?? null);
        $namespace = is_string($cacheConfig['namespace'] ?? null) ? $cacheConfig['namespace'] : 'UDA';
        $defaultPolicy = $this->normalizePolicy($cacheConfig['defaultPolicy'] ?? null);
        $tablePolicies = [];

        if (isset($cacheConfig['tables']) && is_array($cacheConfig['tables'])) {
            foreach ($cacheConfig['tables'] as $table => $policy) {
                if (!is_string($table)) {
                    continue;
                }
                $tablePolicies[strtolower($table)] = $this->normalizePolicy($policy);
            }
        }

        $formatVersion = (int)($cacheConfig['formatVersion'] ?? 1);

        return new CacheSetup(
            $store,
            $tracker,
            $serializer,
            $namespace,
            $defaultPolicy,
            $tablePolicies,
            $formatVersion
        );
    }

    private function buildCacheStore(array $storeConfig): ?CacheStoreInterface
    {
        $type = strtolower($storeConfig['type'] ?? 'array');

        return match ($type) {
            'array' => new ArrayStore(),
            'redis' => $this->buildRedisStore($storeConfig),
            'memcached' => $this->buildMemcachedStore($storeConfig),
            default => null,
        };
    }

    private function buildRedisStore(array $config): ?CacheStoreInterface
    {
        if (!class_exists('\\Redis')) {
            return null;
        }

        $redisClass = 'Redis';
        $redis = new $redisClass();
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 6379);
        $timeout = (float)($config['timeout'] ?? 1.5);

        try {
            $redis->connect($host, $port, $timeout);

            if (!empty($config['auth'])) {
                $redis->auth($config['auth']);
            }
        } catch (Throwable $e) {
            return null;
        }

        $prefix = $config['prefix'] ?? 'UDA:';
        $serializer = $config['serializer'] ?? 'php';

        return new RedisStore($redis, $prefix, $serializer);
    }

    private function buildMemcachedStore(array $config): ?CacheStoreInterface
    {
        if (!class_exists('\\Memcached')) {
            return null;
        }

        $memcachedClass = 'Memcached';
        $memcached = new $memcachedClass();
        $servers = $config['servers'] ?? null;

        if (is_array($servers)) {
            foreach ($servers as $server) {
                if (!is_array($server)) {
                    continue;
                }
                $host = $server['host'] ?? '127.0.0.1';
                $port = (int)($server['port'] ?? 11211);
                $memcached->addServer($host, $port);
            }
        } else {
            $host = $config['host'] ?? '127.0.0.1';
            $port = (int)($config['port'] ?? 11211);
            $memcached->addServer($host, $port);
        }

        $prefix = $config['prefix'] ?? 'UDA:';
        $serializer = $config['serializer'] ?? 'php';

        return new MemcachedStore($memcached, $prefix, $serializer);
    }

    private function normalizePolicy(mixed $policy): array
    {
        if (!is_array($policy)) {
            $policy = [];
        }

        return [
            'ttlSeconds' => max(0, (int)($policy['ttlSeconds'] ?? 0)),
            'minIntervalSeconds' => max(0, (int)($policy['minIntervalSeconds'] ?? 0)),
            'allowStaleOnError' => (bool)($policy['allowStaleOnError'] ?? false),
            'maxStaleSeconds' => max(0, (int)($policy['maxStaleSeconds'] ?? 0)),
            'disabled' => (bool)($policy['disabled'] ?? false),
        ];
    }
    /**
     * Concrete drivers must translate parameters into a PDO DSN string.
     *
     * @param array<string,mixed> $params
     */
    abstract protected function buildDsn(array $params): string;

    /**
     * Get last executed SQL.
     *
     * @return string|null
     */
    public function lastSql(): ?string
    {
        return $this->lastSql;
    }

    /**
     * Get last executed parameters.
     *
     * @return array<string,mixed>
     */
    public function lastParams(): array
    {
        return $this->lastParams;
    }

    /**
     * Drivers can override to provide vendor-specific transient error detection.
     */
    public function isTransientError(Throwable $exception): ?bool
    {
        return null;
    }

    public function consumeStatementCacheHit(): bool
    {
        $hit = $this->lastStatementCacheHit;
        $this->lastStatementCacheHit = false;

        return $hit;
    }

    public function consumeResultCacheHit(): bool
    {
        return $this->cache->consumeLastReadHit();
    }

    /**
     * Get connection name.
     *
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connection;
    }

    /**
     * Get backend name.
     *
     * @return string
     */
    public function getBackendName(): string
    {
        return strtolower((string) ($this->dbtype ?? ''));
    }
}
