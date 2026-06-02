<?php

declare(strict_types=1);

namespace UDA;

/**
 * @package UDA
 * @subpackage Core
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/core/driver
 * @since 1.0.0
 */

/*
 * Purpose: Concrete one-connection execution runtime and sole owner of PDO connections in UDA.
 *
 * Driver owns the hot path from SQL normalization through PDO prepare/execute.
 * Connection loss is handled optimistically: prepare/execute runs once; on a
 * reconnectable PDOException the connection is replaced and the operation is
 * retried once (no per-query ping).
 * It delegates engine-specific string rules to the per-engine classes under
 * UDA\Driver\ (PostgreSQL, SQLite, …). Those classes build the PDO DSN and
 * supply quoting/pagination fragments; they never own PDO or execute SQL.
 * Driver.php calls their static ::dsn(), then new PDO() itself.
 */

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

use UDA\Exception\ConfigException;
use UDA\Exception\ConnectionException;
use UDA\Exception\NotSupportedException;
use UDA\Exception\QueryException;
use UDA\SQL\SqlMessage;

/**
 * Runtime engine for one configured connection name.
 *
 * A Driver instance owns exactly one PDO handle and its transaction state.
 * Process-wide configuration and cache behavior remain keyed by the same
 * connection name so multiple connections of the same RDBMS stay isolated.
 */
final class Driver
{
    /**
     * Maximum cached prepared statements per Driver (bounded memory).
     *
     * @var int
     */
    private const PREPARED_STATEMENT_LRU_MAX = 64;

    /**
     * LRU map: normalised SQL string → PDOStatement for this PDO only.
     *
     * Cleared on reconnect. Insertion order is used for eviction (array_key_first).
     *
     * @var array<string,PDOStatement>
     */
    private array $preparedStatementLru = [];

    /**
     * The PDO instance (sole owner).
     *
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Configured engine name (normalized; e.g. 'sqlserver', 'pgsql', 'mariadb').
     *
     * NOTE: This is the SQL engine family from config, not necessarily the PDO
     * transport prefix. Example: SQL Server may use PDO sqlsrv or dblib depending
     * on platform/config (transport split lands in Phase 3).
     *
     * @var ?string
     */
    private ?string $engine = null;

    /**
     * PDO transport prefix for this connection (e.g. sqlsrv, dblib, pgsql).
     *
     * @var ?string
     */
    private ?string $transport = null;

    /**
     * Connection name (config key).
     *
     * @var string
     */
    private string $connection = 'default';

    /**
     * Raw configuration for this connection.
     *
     * @var array<string,mixed>
     */
    private array $config = [];

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
    private int $savepointCounter = 0;

    /**
     * Open the configured connection and run any initialization SQL.
     * Callers enter through Driver::connect() so the connection name can be
     * resolved consistently with Config defaults.
     *
     * @param ?string $connection  Configured connection name.
     *
     * @throws ConfigException     If the connection config is missing or unsupported.
     * @throws ConnectionException If PDO cannot open the configured connection.
     */
    protected function __construct(?string $connection = 'default')
    {
        $this->connection = $connection ?? Config::default();
        $this->config = Config::connection($this->connection);
        $this->engine = (string) ($this->config['engine'] ?? Config::engine($this->connection));
        $this->transport = (string) ($this->config['transport'] ?? Config::transport($this->connection));

        $user = Config::username($this->connection);
        $pass = Config::password($this->connection);
        $options = $this->resolvePdoOptions();
        $dsn = self::dsn($this->engine, $this->transport, $this->connectionParams($this->config));

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new ConnectionException('Failed to connect to database: ' . $e->getMessage(), 0, $e);
        }

        $this->runInitSql($this->pdo, $this->config);
    }

    /**
     * Quote an identifier using the configured engine rules.
     *
     * @param ?string $engine     Configured engine name or alias.
     * @param string  $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public static function quoteIdentifier(?string $engine, string $identifier): string
    {
        $rules = self::engineRulesClass(self::engineKey($engine));

        return $rules !== null
            ? $rules::quoteIdentifier($identifier)
            : self::quoteAnsiIdentifier($identifier);
    }

    /**
     * Per-engine static rule class for SQL fragments (not DSN — see dsn()).
     *
     * @param string $engineKey  Canonical engine key from engineKey().
     *
     * @return ?class-string Engine rules class, or null when no dedicated rules exist.
     */
    private static function engineRulesClass(string $engineKey): ?string
    {
        return match ($engineKey) {
            'mariadb' => \UDA\Driver\MariaDB::class,
            'sqlserver' => \UDA\Driver\SQLServer::class,
            'sybase' => \UDA\Driver\Sybase::class,
            'sqlite' => \UDA\Driver\SQLite::class,
            'oracle' => \UDA\Driver\Oracle::class,
            'pgsql' => \UDA\Driver\PostgreSQL::class,
            default => null,
        };
    }

    /**
     * Normalize a configured engine name or alias to a canonical engine key.
     *
     * @param ?string $engine  Config driver value or alias.
     *
     * @return string Canonical engine key (e.g. pgsql, mariadb, sqlserver, sybase).
     */
    public static function engineKey(?string $engine): string
    {
        return match (strtolower(trim((string) $engine))) {
            'mysql', 'mariadb' => 'mariadb',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'sqlsrv', 'sqlserver' => 'sqlserver',
            'dblib', 'sybase' => 'sybase',
            'sqlite' => 'sqlite',
            'oci', 'oracle' => 'oracle',
            'db2' => 'db2',
            default => strtolower(trim((string) $engine)),
        };
    }

    /**
     * Normalize a configured transport name or alias to a canonical transport key.
     *
     * @param ?string $transport  Config transport value or alias.
     *
     * @return string Canonical transport key (e.g. sqlsrv, dblib, pgsql).
     */
    public static function transportKey(?string $transport): string
    {
        return match (strtolower(trim((string) $transport))) {
            'sqlsrv', 'sqlserver' => 'sqlsrv',
            'dblib', 'sybase' => 'dblib',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            'mysql', 'mariadb' => 'mysql',
            'oci', 'oracle' => 'oci',
            'sqlite' => 'sqlite',
            default => strtolower(trim((string) $transport)),
        };
    }

    /**
     * Default PDO transport for a canonical engine when config omits transport.
     *
     * @param string $engine  Canonical engine key.
     *
     * @return string Canonical transport key.
     */
    public static function defaultTransport(string $engine): string
    {
        return match (self::engineKey($engine)) {
            'sqlite' => 'sqlite',
            'pgsql' => 'pgsql',
            'mariadb' => 'mysql',
            'sqlserver' => 'sqlsrv',
            'sybase' => 'dblib',
            'oracle' => 'oci',
            default => self::engineKey($engine),
        };
    }

    /**
     * Resolve normalized engine and transport from config driver + optional transport.
     *
     * Legacy configs may use transport names as driver (e.g. dblib, sqlsrv); engineKey
     * and defaultTransport preserve backward-compatible semantics.
     *
     * @param string      $driver     Configured driver (engine or legacy transport alias).
     * @param string|null $transport  Optional explicit transport override.
     *
     * @return array{0:string,1:string} [engine, transport] canonical keys.
     */
    public static function resolveEngineTransport(string $driver, ?string $transport = null): array
    {
        $engine = self::engineKey($driver);
        $resolvedTransport = ($transport !== null && trim($transport) !== '')
            ? self::transportKey($transport)
            : self::defaultTransport($engine);

        return [$engine, $resolvedTransport];
    }

    /**
     * Normalize allowlist column names.
     *
     * @param string $column     Column name or expression.
     * @param array  $allowlist  Allowed column names.
     *
     * @return ?string String result, or null when absent.
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

    /**
     * Strip identifier quotes.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string String result.
     */
    protected function stripIdentifierQuotes(string $identifier): string
    {
        return trim($identifier, "`\"[]");
    }

    /**
     * ORDER BY helper respecting allowlist rules.
     *
     * @param string $column     Column name or expression.
     * @param array  $allowlist  Allowed column names.
     * @param string $direction  Sort direction.
     *
     * @return string String result.
     *
     * @throws QueryException If the operation fails.
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
     *
     * @param int $limit   Maximum number of rows.
     * @param int $offset  Number of rows to skip.
     *
     * @return string Pagination SQL fragment.
     *
     * @throws QueryException If the operation fails.
     */
    public function limitOffset(int $limit, int $offset): string
    {
        if ($limit < 0 || $offset < 0) {
            throw new QueryException('LIMIT/OFFSET must be non-negative');
        }

        return self::limitOffsetFor($this->engine, $limit, $offset);
    }

    /**
     * Named-parameter IN clause helper.
     *
     * @param array  $values  Values to process.
     * @param string $hint    Parameter name hint.
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
     * Q.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    public function q(string $identifier): string
    {
        return self::quoteIdentifier($this->engine, $identifier);
    }

    /**
     * Build a PDO DSN for the configured engine (and transport when the engine has more than one).
     *
     * Per-engine classes under UDA\Driver\ own DSN construction for their engine.
     * Driver.php calls this, then performs new PDO() — the subdirectory classes never
     * create or hold PDO handles.
     *
     * @param ?string             $engine     Canonical engine key.
     * @param ?string             $transport  PDO prefix when engine supports multiple (sqlserver only today).
     * @param array<string,mixed> $params
     *
     * @return string PDO DSN string.
     *
     * @throws ConfigException If the operation fails.
     */
    private static function dsn(?string $engine, ?string $transport, array $params): string
    {
        return match (self::engineKey($engine)) {
            'pgsql' => \UDA\Driver\PostgreSQL::dsn($params),
            'mariadb' => \UDA\Driver\MariaDB::dsn($params),
            'sqlite' => \UDA\Driver\SQLite::dsn($params),
            'oracle' => \UDA\Driver\Oracle::dsn($params),
            'sqlserver' => self::transportKey($transport) === 'dblib'
                ? \UDA\Driver\Dblib::dsn($params)
                : \UDA\Driver\SQLServer::dsn($params),
            'sybase' => \UDA\Driver\Sybase::dsn($params),
            default => throw new ConfigException('Unsupported database engine: ' . (string) $engine),
        };
    }

    /**
     * Quote an identifier with ANSI double quotes.
     *
     * @param string $identifier  Identifier value.
     *
     * @return string Quoted identifier.
     */
    private static function quoteAnsiIdentifier(string $identifier): string
    {
        $clean = trim($identifier);
        $escaped = str_replace('"', '""', $clean);

        return '"' . $escaped . '"';
    }

    /**
     * Limit/offset fragment for an engine.
     *
     * @param ?string $engine  Configured engine name.
     * @param int     $limit    Maximum number of rows.
     * @param int     $offset   Number of rows to skip.
     *
     * @return string Pagination SQL fragment.
     */
    private static function limitOffsetFor(?string $engine, int $limit, int $offset): string
    {
        $rules = self::engineRulesClass(self::engineKey($engine));

        if ($rules !== null && method_exists($rules, 'limitOffset')) {
            return $rules::limitOffset($limit, $offset);
        }

        return sprintf('LIMIT %d OFFSET %d', $limit, $offset);
    }

    /**
     * Create a Driver for the named connection.
     *
     * Each call returns a new instance. Connection pooling is the responsibility
     * of Database::connect(), which caches Database (and therefore Driver) instances
     * by name. Driver instances are long-lived and self-heal: if prepare/execute
     * fails with a reconnectable connection error, the Driver reconnects and retries
     * once transparently.
     *
     * @param ?string $connection  Connection name, or null for the default.
     *
     * @return self
     *
     * @throws ConfigException     If the connection config is missing or unsupported.
     * @throws ConnectionException If PDO connection fails.
     */
    public static function connect(?string $connection = null): self
    {
        return new self($connection ?? Config::default());
    }

    /**
     * Whether a PDOException indicates a dropped server connection worth one reconnect retry.
     *
     * Uses SQLSTATE class 08 (connection exception) plus common MySQL driver codes.
     *
     * @param PDOException $exception  PDO failure from prepare() or execute().
     *
     * @return bool True when executeInternal() may reconnect and retry once.
     */
    private function isReconnectableConnectionLost(PDOException $exception): bool
    {
        $info = $exception->errorInfo ?? null;
        $state = is_array($info) ? strtoupper((string) ($info[0] ?? '')) : '';
        $driverCode = is_array($info) && isset($info[1]) ? (int) $info[1] : 0;

        if ($state !== '' && str_starts_with($state, '08')) {
            return true;
        }

        if (in_array($driverCode, [2006, 2013], true)) {
            return true;
        }

        if ($state === 'HY000') {
            $msg = strtolower($exception->getMessage());

            return str_contains($msg, 'gone away') || str_contains($msg, 'lost connection');
        }

        return false;
    }

    /**
     * Replace the PDO instance with a fresh connection using the stored config.
     *
     * Called from executeInternal() after a reconnectable connection failure.
     * Runs init SQL after reconnection to restore session state.
     *
     * @throws ConnectionException If the reconnection attempt fails.
     */
    private function reconnect(): void
    {
        $this->clearPreparedStatementLru();

        $user    = Config::username($this->connection);
        $pass    = Config::password($this->connection);
        $options = $this->resolvePdoOptions();
        $dsn     = self::dsn($this->engine, $this->transport, $this->connectionParams($this->config));

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new ConnectionException('Reconnection failed: ' . $e->getMessage(), 0, $e);
        }

        $this->runInitSql($this->pdo, $this->config);
    }

    /**
     * Drop all cached PDOStatement objects (required before replacing PDO).
     *
     * @return void
     */
    private function clearPreparedStatementLru(): void
    {
        $this->preparedStatementLru = [];
    }

    /**
     * Return a prepared statement for this query, reusing a cached one when possible.
     *
     * @param string $query  SQL after normalisation (named parameters only).
     *
     * @return PDOStatement
     *
     * @throws PDOException When prepare() fails (caller maps to QueryException).
     */
    private function getOrPrepareStatement(string $query): PDOStatement
    {
        if (isset($this->preparedStatementLru[$query])) {
            $stmt = $this->preparedStatementLru[$query];
            unset($this->preparedStatementLru[$query]);
            $this->preparedStatementLru[$query] = $stmt;

            return $stmt;
        }

        $stmt = $this->pdo->prepare($query);

        if (count($this->preparedStatementLru) >= self::PREPARED_STATEMENT_LRU_MAX) {
            $oldest = array_key_first($this->preparedStatementLru);

            if ($oldest !== null) {
                unset($this->preparedStatementLru[$oldest]);
            }
        }

        $this->preparedStatementLru[$query] = $stmt;

        return $stmt;
    }

    /**
     * Resolve credentials from config.
     * NOTE: {env:VAR} resolution belongs to Config.
     *
     * @param array<string,mixed> $conn  Connection config.
     *
     * @return array{0:string|null,1:string|null}
     */
    private function connectionParams(array $conn): array
    {
        $params = $conn['params'] ?? null;

        if (is_array($params)) {
            return $params;
        }

        $params = $conn;

        if (isset($params['database']) && !isset($params['dbname'])) {
            $params['dbname'] = $params['database'];
        }

        if (isset($params['database']) && !isset($params['path'])) {
            $params['path'] = $params['database'];
        }

        if (isset($params['server']) && !isset($params['host'])) {
            $params['host'] = $params['server'];
        }

        return $params;
    }

    /**
     * Resolve PDO options.
     * Baseline defaults are enforced here; config can override/extend.
     * PDO-driver-specific options may be applied here if needed.
     *
     * @param string              $pdoDriver  PDO driver name ('pgsql', 'mysql', 'sqlsrv', 'dblib', 'sqlite').
     * @param array<string,mixed> $conn       Connection config.
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

        $opts = array_replace($defaults, $opt);

        // UDA requires exceptions for all error handling including reconnect classification.
        // This cannot be overridden by consumer config.
        $opts[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

        return $opts;
    }

    /**
     * Execute init_sql statements.
     *
     * @param PDO                 $pdo   PDO instance.
     * @param array<string,mixed> $conn  Connection config.
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
     * @param string|SqlMessage  $sql     SQL statement or SQL message.
     * @param array<string,mixed> $params  Named parameters.
     * @param array<string>|null  $tables  Optional table names for cache invalidation.
     *
     * @return int
     *
     * @throws QueryException
     */
    public function exec(string|SqlMessage $sql, array $params = [], ?array $tables = null): int
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);
        $stmt = $this->executeInternal($message, $normalized);
        $affected = $stmt->rowCount();

        if ($affected > 0 && $tableHints !== []) {
            Cache::touch($this->connection, $tableHints);
        }

        $stmt->closeCursor();

        return $affected;
    }

    /**
     * Execute a DML statement that emits RETURNING/OUTPUT rows.
     *
     * @param string|SqlMessage $sql     SQL string or SQL message.
     * @param array             $params  Named parameter values.
     * @param ?array            $tables  Table names used for cache metadata.
     *
     * @return array Result array.
     */
    public function returning(string|SqlMessage $sql, array $params = [], ?array $tables = null): array
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        if (self::engineKey($this->engine) === 'oracle' && $message->getReturningColumns() !== []) {
            $rows = $this->oracleReturning($message, $normalized);

            if ($rows !== [] && $tableHints !== []) {
                Cache::touch($this->connection, $tableHints);
            }

            return $rows;
        }

        $stmt = $this->executeInternal($message, $normalized);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $affected = $stmt->rowCount();

        if ($affected > 0 && $tableHints !== []) {
            Cache::touch($this->connection, $tableHints);
        }

        $stmt->closeCursor();

        return $rows;
    }

    /**
     * Execute a SELECT statement and return all rows.
     *
     * @param string|SqlMessage        $sql     SQL statement or SqlMessage.
     * @param array<string,mixed>      $params  Named parameters.
     * @param null|callable(PDOStatement,array<string,mixed>):void $binder
     * @param array<string>|null  $tables  Optional cache hint tables.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws QueryException
     */
    public function rows(string|SqlMessage $sql, array $params = [], ?array $tables = null): array
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            'rows',
            function () use ($message, $normalized): array {
                $stmt = $this->executeInternal($message, $normalized);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $stmt->closeCursor();

                return $rows;
            }
        );
    }

    /**
     * Execute a SELECT statement and return the first row (or null).
     *
     * @param string|SqlMessage  $sql     SQL statement or SQL message.
     * @param array<string,mixed> $params  Named parameters.
     * @param array<string>|null  $tables  Optional cache hint tables.
     *
     * @return array<string,mixed>|null
     *
     * @throws QueryException
     */
    public function row(string|SqlMessage $sql, array $params = [], ?array $tables = null): ?array
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            'row',
            fn () => $this->rowNoCache($message, $normalized)
        );
    }

    /**
     * Execute a SELECT statement and return a single value.
     *
     * @param string|SqlMessage  $sql     SQL statement or SQL message.
     * @param array<string,mixed> $params  Named parameters.
     * @param array<string>|null  $tables  Optional cache hint tables.
     *
     * @return mixed
     *
     * @throws QueryException
     */
    public function value(string|SqlMessage $sql, array $params = [], ?array $tables = null): mixed
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            'value',
            function () use ($message, $normalized): mixed {
                $stmt = $this->executeInternal($message, $normalized);

                if ($stmt->columnCount() !== 1) {
                    $stmt->closeCursor();
                    throw new QueryException('value() requires a single column result');
                }

                $scalar = $stmt->fetchColumn(0);
                $stmt->closeCursor();

                return $scalar === false ? null : $scalar;
            }
        );
    }

    /**
     * Return the first column from all rows.
     *
     * @param string|SqlMessage $sql     SQL string or SQL message.
     * @param array             $params  Named parameter values.
     * @param ?array            $tables  Table names used for cache metadata.
     *
     * @return array Result array.
     */
    public function values(string|SqlMessage $sql, array $params = [], ?array $tables = null): array
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            'values',
            function () use ($message, $normalized): array {
                $stmt = $this->executeInternal($message, $normalized);
                $col = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                $stmt->closeCursor();

                return $col;
            }
        );
    }

    /**
     * Return the first row as a numeric list.
     *
     * @param string|SqlMessage $sql     SQL string or SQL message.
     * @param array             $params  Named parameter values.
     * @param ?array            $tables  Table names used for cache metadata.
     *
     * @return ?array<int,mixed> Result row values or null.
     */
    public function list(string|SqlMessage $sql, array $params = [], ?array $tables = null): ?array
    {
        $tableHints = $tables ?? ($sql instanceof SqlMessage ? $sql->getCacheTables() : []);
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);

        return $this->executeRead(
            $message,
            $tableHints,
            'list',
            function () use ($message, $normalized): ?array {
                $row = $this->rowNoCache($message, $normalized);

                return $row === null ? null : array_values($row);
            }
        );
    }

    /**
     * Iterate over rows with a callback.
     *
     * @param string|SqlMessage $sql     SQL string or SQL message.
     * @param array|callable    $params  Named parameter values.
     * @param callable          $fn      Callback to execute.
     * @param ?array            $tables  Table names used for cache metadata.
     *
     * @return int Integer result.
     *
     * @throws QueryException If the operation fails.
     */
    public function each(string|SqlMessage $sql, array|callable $params, ?callable $fn = null, ?array $tables = null): int
    {
        if ($fn === null) {
            if (!is_callable($params)) {
                throw new QueryException('each() requires a callback');
            }
            $fn = $params;
            $params = [];
        } elseif (!is_array($params)) {
            throw new QueryException('each() parameters must be provided as an array when callback is supplied');
        }

        $count = 0;

        foreach ($this->stream($sql, is_array($params) ? $params : []) as $row) {
            $fn($row);
            $count++;
        }

        return $count;
    }

    /**
     * Stream rows from the database cursor.
     *
     * @param string|SqlMessage $sql     SQL string or SQL message.
     * @param array             $params  Named parameter values.
     *
     * @return \Generator<int,array<string,mixed>>
     *
     * @throws QueryException If the operation fails.
     */
    public function stream(string|SqlMessage $sql, array $params = []): \Generator
    {
        [$message, $normalized] = $this->normalizeToSqlMessage($sql, $params);
        $stmt = $this->executeInternal($message, $normalized);

        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Internal hot path: normalize, prepare, execute.
     * This is the only method allowed to call PDO::prepare() and PDOStatement::execute().
     *
     * On a reconnectable connection-lost error from prepare() or execute(), reconnects
     * once and retries the same operation. No proactive ping runs on the happy path.
     *
     * @param string|SqlMessage                              $sql     SQL statement or SqlMessage.
     * @param array<string,mixed>                            $params  Named parameters.
     * @param null|callable(PDOStatement,array<string,mixed>):void $binder  Optional output binder.
     *
     * @return PDOStatement
     *
     * @throws QueryException
     */
    protected function executeInternal(string|SqlMessage $sql, array $params, ?callable $binder = null): PDOStatement
    {
        [$query, $mergedParams] = $this->normalizeSql($sql, $params);

        $this->lastSql = $query;
        $this->lastParams = $mergedParams;

        $attempts = 0;

        while (true) {
            $stmt = null;

            try {
                $stmt = $this->getOrPrepareStatement($query);
                $executeParams = $mergedParams;

                if ($binder !== null) {
                    $binder($stmt, $mergedParams);
                    $executeParams = null;
                }

                $stmt->execute($executeParams);

                return $stmt;
            } catch (PDOException $ex) {
                if ($stmt !== null) {
                    $stmt->closeCursor();
                }

                if ($attempts < 1 && $this->isReconnectableConnectionLost($ex)) {
                    $attempts++;
                    $this->reconnect();

                    continue;
                }

                $prefix = $stmt === null ? 'Failed to prepare statement' : 'Query execution failed';

                throw QueryException::fromPdo($prefix, $ex);
            }
        }
    }

    /**
     * Normalize SQL inputs to a raw query string and merged parameters.
     *
     * @param string|SqlMessage   $sql     SQL input.
     * @param array<string,mixed> $params  Additional parameters.
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
            throw QueryException::guardrail('Positional parameters are forbidden in public API');
        }

        return [$query, $params];
    }

    /**
     * Convert input into a SqlMessage while preserving merged parameters.
     *
     * @param string|SqlMessage $sql     SQL input from callers.
     * @param array             $params  Named parameter values.
     *
     * @return array{0:SqlMessage,1:array<string,mixed>}
     */
    protected function normalizeToSqlMessage(string|SqlMessage $sql, array $params): array
    {
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

    /**
     * Execute without cache semantics; return the first row only.
     *
     * Callers must constrain SQL (for example LIMIT 1) when at most one row is intended.
     * Additional result rows are not consumed here; closeCursor() releases the statement.
     *
     * @param string|SqlMessage $sql     SQL string, SQL message, or builder SQL object.
     * @param array             $params  Named parameter values.
     *
     * @return ?array Result array.
     *
     * @throws QueryException If the operation fails.
     */
    protected function rowNoCache(string|SqlMessage $sql, array $params): ?array
    {
        $stmt = $this->executeInternal($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $stmt->closeCursor();

            return null;
        }

        $stmt->closeCursor();

        return $row;
    }

    /**
     * Oracle RETURNING INTO requires bound output params, so Driver owns this
     * specialized execution path while Oracle provides only engine SQL rules.
     *
     * @param SqlMessage          $message     SQL message to execute.
     * @param array<string,mixed> $normalized
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws QueryException If the operation fails.
     */
    private function oracleReturning(SqlMessage $message, array $normalized): array
    {
        $columns = $message->getReturningColumns();
        $valueSets = $message->getValuePlaceholders();

        if ($valueSets === [] || count($valueSets) <= 1) {
            return $this->runOracleReturningStatement($message->getQuery(), $normalized, $columns);
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
     * Run oracle returning statement.
     *
     * @param string              $baseQuery  Base SQL statement.
     * @param array<string,mixed> $params
     * @param array<int,string>   $columns
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws QueryException If the operation fails.
     */
    private function runOracleReturningStatement(string $baseQuery, array $params, array $columns): array
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
            $stmt = $this->executeInternal(
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
                            \UDA\Driver\Oracle::returningBufferLength()
                        );
                    }
                }
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

    /**
     * Execute read.
     *
     * @param SqlMessage        $message   SQL message to execute.
     * @param array<int,string> $tables
     * @param callable():T      $executor
     *
     * @return T
     *
     * @template T of array|null
     */
    protected function executeRead(SqlMessage $message, array $tables, string $shape, callable $executor): mixed
    {
        if ($tables !== [] && Config::hasCache($this->connection, $tables)) {
            $cached = Cache::read($this->connection, $message, $shape);

            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $executor();

        if (is_array($result) && $tables !== [] && Config::hasCache($this->connection, $tables)) {
            Cache::put($this->connection, $message, $tables, $result, $shape);
        }

        return $result;
    }

    /**
     * Execute a callback within a database transaction.
     * Nested transactions are implemented using SAVEPOINT.
     *
     * @return mixed
     *
     * @throws Throwable Re-throws anything from callback after rollback.
     *
     * @param callable(self): mixed $fn Callback to execute within the transaction.
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
     * Concrete engines may override naming rules if required.
     *
     * @return string
     */
    protected function createSavepointName(): string
    {
        $this->savepointCounter++;

        return 'uda_sp_' . $this->savepointCounter;
    }

    /**
     * Savepoint sql.
     *
     * @param string $name  Name value.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    protected function savepointSql(string $name): ?string
    {
        $rules = self::engineRulesClass(self::engineKey($this->engine));

        if ($rules !== null && method_exists($rules, 'savepointSql')) {
            return $rules::savepointSql($name);
        }

        return "SAVEPOINT {$name}";
    }

    /**
     * Release savepoint sql.
     *
     * @param string $name  Name value.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    protected function releaseSavepointSql(string $name): ?string
    {
        $rules = self::engineRulesClass(self::engineKey($this->engine));

        if ($rules !== null && method_exists($rules, 'releaseSavepointSql')) {
            return $rules::releaseSavepointSql($name);
        }

        return "RELEASE SAVEPOINT {$name}";
    }

    /**
     * Rollback savepoint sql.
     *
     * @param string $name  Name value.
     *
     * @return ?string Savepoint SQL fragment, or null when unsupported.
     */
    protected function rollbackSavepointSql(string $name): ?string
    {
        $rules = self::engineRulesClass(self::engineKey($this->engine));

        if ($rules !== null && method_exists($rules, 'rollbackSavepointSql')) {
            return $rules::rollbackSavepointSql($name);
        }

        return "ROLLBACK TO SAVEPOINT {$name}";
    }

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
     * Get connection name.
     *
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->connection;
    }

    /**
     * Configured engine name for this connection (normalized lowercase).
     *
     * @return string
     */
    public function engineName(): string
    {
        return self::engineKey($this->engine);
    }

    /**
     * Configured PDO transport for this connection (normalized lowercase).
     *
     * @return string
     */
    public function transportName(): string
    {
        return self::transportKey($this->transport);
    }
}
