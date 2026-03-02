<?php

declare(strict_types=1);

/**
 * @package     UDA
 * @subpackage  Core
 * @author      James Dornan <james.dornan@uda.example.com>
 * @license     GPL-2.0-only
 * @link        https://docs.uda.example.com/core/database
 * @since       1.0.0
 *
 * Primary ingress for UDA - provides uniform database access and coordinates execution.
 * This class serves as the sole public entry point for all database operations.
 * It coordinates connection selection, delegates to internal Driver for execution,
 * and provides the uniform execution surface required by the UDA constitution.
 * Application code MUST use this class exclusively for data access.
 *
 * The purpose of this class is to provide a consistent, high-level API for database
 * operations that abstracts away connection management, driver selection, and caching
 * while enforcing architectural boundaries between application code and the underlying
 * database implementation, preventing scope creep into driver-specific concerns.
 */
final class Database
{
    /** @var ?Driver Lazy-initialized Driver instance */
    private ?Driver $driver = null;
    
    /** @var string Connection name */
    private string $connectionName;
    
    /** @var array Connection configuration */
    private array $connectionConfig;
    
    /** @var array Full configuration */
    private array $fullConfig;
    
    /** @var array Reflection cache to avoid repeated ReflectionClass creation */
    private static array $reflectionCache = [];
    
    /**
     * 
     * @param string $connectionName The connection name
     * @param array $connectionConfig The connection configuration
     * @param array $fullConfig The full configuration
     */
    private function __construct(string $connectionName, array $connectionConfig, array $fullConfig)
    {
        $this->connectionName = $connectionName;
        $this->connectionConfig = $connectionConfig;
        $this->fullConfig = $fullConfig;
    }
    
    /**
     * 
     * @param array $config The connection configuration
     * @return string The DSN string
     * @throws ConfigException If driver is unsupported
     */
    private static function getDsn(array $config): string
    {
        $driver = $config['driver'] ?? '';
        $params = $config['params'] ?? [];
        
        switch ($driver) {
            case 'sqlite':
                $path = $params['path'] ?? ':memory:';
                return "sqlite:$path";
                
            case 'pgsql':
                $parts = ['pgsql'];
                if (isset($params['host'])) {
                    $parts[] = 'host=' . $params['host'];
                }
                if (isset($params['port'])) {
                    $parts[] = 'port=' . $params['port'];
                }
                if (isset($params['dbname'])) {
                    $parts[] = 'dbname=' . $params['dbname'];
                }
                if (isset($params['sslmode'])) {
                    $parts[] = 'sslmode=' . $params['sslmode'];
                }
                return implode(';', $parts);
                
            case 'mysql':
                $parts = ['mysql'];
                if (isset($params['host'])) {
                    $parts[] = 'host=' . $params['host'];
                }
                if (isset($params['port'])) {
                    $parts[] = 'port=' . $params['port'];
                }
                if (isset($params['dbname'])) {
                    $parts[] = 'dbname=' . $params['dbname'];
                }
                if (isset($params['charset'])) {
                    $parts[] = 'charset=' . $params['charset'];
                }
                return implode(';', $parts);
                
            case 'sqlsrv':
                $parts = [];
                if (isset($params['server'])) {
                    $parts[] = 'Server=' . $params['server'];
                    if (isset($params['port'])) {
                        $parts[count($parts)-1] .= ',' . $params['port'];
                    }
                }
                if (isset($params['database'])) {
                    $parts[] = 'Database=' . $params['database'];
                }
                return 'sqlsrv:' . implode(';', $parts);
                
            default:
                throw new ConfigException("Unsupported driver: $driver");
        }
    }
    
    /**
     * 
     * @param array $config The connection configuration
     * @return array The username and password
     */
    private static function getCredentials(array $config): array
    {
        $user = null;
        $pass = null;
        
        // Handle user configuration
        if (isset($config['user'])) {
            if (is_string($config['user'])) {
                $user = $config['user'];
            } elseif (is_array($config['user']) && isset($config['user']['env'])) {
                $user = getenv($config['user']['env']) ?: null;
            }
        }
        
        // Handle password configuration
        if (isset($config['pass'])) {
            if (is_string($config['pass'])) {
                $pass = $config['pass'];
            } elseif (is_array($config['pass']) && isset($config['pass']['env'])) {
                $pass = getenv($config['pass']['env']) ?: null;
            }
        }
        
        return [$user, $pass];
    }
    
    /**
     * 
     * @param ?string $name The connection name
     * @param ?string $configFile The configuration file path
     * @param ?array $options Additional options
     * @return self The Database instance
     * @throws ConfigException If connection configuration is invalid
     */
    public static function connect(?string $name = null, ?string $configFile = null, ?array $options = null): self
    {
        // Two configuration loading strategies:
        // 1. From environment variable (UDA_CONFIG) - production default
        // 2. From specific file path - development/testing override
        if ($configFile === null) {
            // Load from environment variable or default location
            // Config::loadFromEnv() returns empty snapshot if no env var is set
            $snapshot = Config::loadFromEnv();
            
            // Convert immutable snapshot to mutable array format for compatibility
            // This is a bridge between new immutable config system and existing code
            $config = [
                'connections' => [],
                'defaults' => []
            ];
            
            // Populate connections array from snapshot
            foreach ($snapshot->getConnectionNames() as $connName) {
                $config['connections'][$connName] = $snapshot->getConnection($connName);
            }
            $config['default'] = $snapshot->getDefaultConnection();
        } else {
            // Load from specific file - useful for testing or explicit configuration
            $snapshot = Config::load($configFile);
            $config = [
                'connections' => [],
                'defaults' => []
            ];
            
            // Same conversion process as above
            foreach ($snapshot->getConnectionNames() as $connName) {
                $config['connections'][$connName] = $snapshot->getConnection($connName);
            }
            $config['default'] = $snapshot->getDefaultConnection();
        }
        
        // Resolve connection name (default if none provided)
        $connectionName = self::resolveConnectionName($config, $name);
        
        // Fetch connection configuration or throw if not found
        $connection = $config['connections'][$connectionName] ?? null;

        if (!is_array($connection)) {
            throw new ConfigException("Connection '{$connectionName}' not found in configuration");
        }

        // Return new Database instance (not Driver!) - lazy initialization pattern
        // Driver creation happens on first query execution via getDriver()
        return new self($connectionName, $connection, $config);
    }
    
    /**
     * Retrieves the lazy-initialized Driver instance for this connection.
     *
     * The Driver is instantiated on first access and reused for subsequent calls.
     * This strategy avoids unnecessary PDO connections for applications that
     * initialize Database objects but never execute queries.
     *
     * @return Driver The Driver instance for low-level query execution
     * @throws \UDA\Exception\ConnectionException If driver creation fails
     *
     * @see Database::createDriver() Driver instantiation details
     * @see Driver::class Core execution engine
     * @example
     * // Access the driver for direct operations
     * $driver = $db->getDriver();
     * $version = $driver->value("SELECT version()");
     */
    private function getDriver(): Driver
    {
        if ($this->driver === null) {
            $this->driver = $this->createDriver();
        }
        return $this->driver;
    }
    
    /**
     * 
     * @return Driver The created Driver instance
     * @throws ConfigException If driver configuration is invalid
     * @throws ConnectionException If connection fails
     */
    private function createDriver(): Driver
    {
        $driverName = $this->connectionConfig['driver'] ?? '';

        if ($driverName === '') {
            throw new ConfigException("Connection '{$this->connectionName}' missing driver");
        }

        $dsn = self::getDsn($this->connectionConfig);
        [$user, $pass] = self::getCredentials($this->connectionConfig);
        $options = array_replace([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ], self::resolveOptions($this->fullConfig, $this->connectionConfig));

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $ex) {
            throw new ConnectionException("Failed to connect to '{$this->connectionName}': " . $ex->getMessage(), 0, $ex);
        }

        $cacheOverride = $options['cache'] ?? null;
        $cacheSetup = Cache::setup($this->fullConfig, $this->connectionConfig, $this->connectionName, $cacheOverride);
        return Driver::fromConfig($this->connectionName, $this->connectionConfig, $pdo, $cacheSetup);
    }
    
    /**
     * 
     * @param array $config The configuration
     * @param ?string $name The connection name
     * @return string The resolved connection name
     * @throws ConfigException If no connection name can be resolved
     */
    private static function resolveConnectionName(array $config, ?string $name): string
    {
        if ($name !== null) {
            return $name;
        }

        if (isset($config['default']) && is_string($config['default'])) {
            return $config['default'];
        }

        if (isset($config['defaults']['connection']) && is_string($config['defaults']['connection'])) {
            return $config['defaults']['connection'];
        }

        throw new ConfigException('No connection name provided and no default configured');
    }

    /**
     * 
     * @param array $config The configuration
     * @param array $connection The connection configuration
     * @return array The resolved options
     */
    private static function resolveOptions(array $config, array $connection): array
    {
        $defaults = $config['defaults']['options'] ?? [];
        $overrides = $connection['options'] ?? [];

        return array_merge($defaults, $overrides);
    }
    
    // ----- Public Execution Methods -----

    /**
     * Executes a SELECT query and returns all matching rows.
     *
     * This is the primary method for retrieving tabular data from the database.
     * Results are returned as an array of associative arrays, where each row uses
     * column names as keys. Supports caching if configured.
     *
     * @param string $sql The SQL query to execute (may use named parameters)
     * @param array<string, mixed> $params Named query parameters
     * @param ?array<string> $tables Tables involved in the query (for cache invalidation)
     * @return array<array<string, mixed>> Array of associative arrays representing rows
     * @throws QueryException If query execution fails
     *
     * @see Database::row() For single-row queries
     * @see Database::value() For single-value extraction
     * @example
     * // Query with named parameters
     * $users = $db->rows(
     *     "SELECT * FROM users WHERE status = :status ORDER BY name",
     *     ['status' => 'active'],
     *     ['users']  // Hint for cache invalidation
     * );
     */
    public function rows(string $sql, array $params = [], ?array $tables = null): array
    {
        return $this->getDriver()->rows($sql, $params, $tables);
    }

    /**
     * 
     * @param string $sql The SQL query
     * @param array $params The query parameters
     * @param ?array $tables The tables for cache hinting
     * @return ?array The single row result or null
     */
    public function row(string $sql, array $params = [], ?array $tables = null): ?array
    {
        return $this->getDriver()->row($sql, $params, $tables);
    }

    /**
     * 
     * @param string $sql The SQL query
     * @param array $params The query parameters
     * @param ?array $tables The tables for cache hinting
     * @return mixed The single value result
     */
    public function value(string $sql, array $params = [], ?array $tables = null)
    {
        return $this->getDriver()->value($sql, $params, $tables);
    }

    /**
     * 
     * @param string $sql The SQL query
     * @param array $params The query parameters
     * @param ?array $tables The tables for cache hinting
     * @return array The values from the first column
     */
    public function values(string $sql, array $params = [], ?array $tables = null): array
    {
        return $this->getDriver()->values($sql, $params, $tables);
    }

    /**
     * 
     * @param string $sql The SQL query
     * @param array $params The query parameters
     * @param ?array $tables The tables for cache hinting
     * @return array The values from the first column
     */
    public function list(string $sql, array $params = [], ?array $tables = null): array
    {
        return $this->getDriver()->list($sql, $params, $tables);
    }

    /**
     * 
     * @param string $sql The SQL query
     * @param array|callable $params The query parameters or callback function
     * @param ?callable $fn The callback function (if params is array)
     * @return int The number of rows processed
     */
    public function each(string $sql, array|callable $params, callable $fn = null): int
    {
        return $this->getDriver()->each($sql, $params, $fn);
    }

    /**
     * 
     * @param string|SqlMessage|\UDA\Query\Sql $sql The SQL query or SqlMessage
     * @param array $params The query parameters
     * @param ?array $tables The tables for cache invalidation
     * @return int The number of affected rows
     */
    public function exec(string|SqlMessage|\UDA\Query\Sql $sql, array $params = [], ?array $tables = null): int
    {
        return $this->getDriver()->exec($sql, $params, $tables);
    }

    /**
     * 
     * @param string $column The column name
     * @param array $allowlist The allowlist of valid columns
     * @param string $direction The sort direction
     * @return string The ORDER BY fragment
     */
    public function orderByAllowed(string $column, array $allowlist, string $direction = 'ASC'): string
    {
        return $this->getDriver()->orderByAllowed($column, $allowlist, $direction);
    }

    /**
     * 
     * @param int $limit The limit value
     * @param int $offset The offset value
     * @return \UDA\Query\SqlMessage The SqlMessage with LIMIT/OFFSET fragment
     */
    public function limitOffset(int $limit, int $offset): \UDA\Query\SqlMessage
    {
        return $this->getDriver()->limitOffset($limit, $offset);
    }

    /**
     * 
     * @param array $values The values for the IN list
     * @param string $hint The parameter hint
     * @return \UDA\Query\SqlMessage The SqlMessage with IN list fragment
     */
    public function inList(array $values, string $hint = 'p'): \UDA\Query\SqlMessage
    {
        return $this->getDriver()->inList($values, $hint);
    }

    /**
     * 
     * @param string $identifier The identifier to quote
     * @return string The quoted identifier
     */
    public function q(string $identifier): string
    {
        return $this->getDriver()->q($identifier);
    }

    /**
     * Executes a callback within a database transaction.
     *
     * Provides a clean, closure-based API for transaction management. The code
     * within the callback executes atomically - either all changes succeed (and
     * are committed) or none do (triggering a rollback).
     *
     * @param callable(Database): mixed $fn Callback to execute in the transaction
     *        The callback receives this Database instance as its sole argument.
     * @return mixed The return value of the callback
     * @throws \Throwable Any exception thrown by the callback (triggers rollback)
     *
     * @see Driver::transaction() Underlying transaction implementation
     * @example
     * // Transfer money between accounts atomically
     * $result = $db->transaction(function (Database $db) {
     *     $db->exec(
     *         "UPDATE accounts SET balance = balance - :amount WHERE id = :from",
     *         ['amount' => 100, 'from' => 1]
     *     );
     *     $db->exec(
     *         "UPDATE accounts SET balance = balance + :amount WHERE id = :to",
     *         ['amount' => 100, 'to' => 2]
     *     );
     *     return true;
     * }
     */
    public function transaction(callable $fn): mixed
    {
        return $this->getDriver()->transaction($fn);
    }

    /**
     * 
     * @return ?string The last executed SQL or null
     */
    public function lastSql(): ?string
    {
        return $this->getDriver()->lastSql();
    }

    /**
     * 
     * @return array The last executed parameters
     */
    public function lastParams(): array
    {
        return $this->getDriver()->lastParams();
    }
    
    // ----- Query Builder Methods -----

    /**
     * Creates a new SELECT query builder for fluent query construction.
     *
     * The query builder provides a chainable API for creating complex SELECT queries
     * without writing raw SQL. Automatically injects the driver instance into the
     * builder for seamless execution.
     *
     * @return \UDA\Query\Select Ready-to-configure SELECT query builder
     *
     * @see \UDA\Query\Select Fluent method reference
     * @see Database::selectRows() Execute the built query
     * @example
     * // Build and execute a complex query
     * $users = $db->select()
     *     ->from('users')
     *     ->where('status = ? AND created_at > ?', 'active', '2023-01-01')
     *     ->orderBy('name')
     *     ->limit(10)
     *     ->selectRows();
     */
    public function select(): \UDA\Query\Select
    {
        $query = $this->getDriver()->select();
        // Set the driver instance for constitutional delegation
        $this->setDriverInstance($query);
        return $query;
    }

    /**
     * 
     * @return \UDA\Query\Insert The INSERT query builder
     */
    public function insert(): \UDA\Query\Insert
    {
        $query = $this->getDriver()->insert();
        // Set the driver instance for constitutional delegation
        $this->setDriverInstance($query);
        return $query;
    }

    /**
     * 
     * @return \UDA\Query\Update The UPDATE query builder
     */
    public function update(): \UDA\Query\Update
    {
        $query = $this->getDriver()->update();
        // Set the driver instance for constitutional delegation
        $this->setDriverInstance($query);
        return $query;
    }

    /**
     * 
     * @return \UDA\Query\Delete The DELETE query builder
     */
    public function delete(): \UDA\Query\Delete
    {
        $query = $this->getDriver()->delete();
        // Set the driver instance for constitutional delegation
        $this->setDriverInstance($query);
        return $query;
    }

    /**
     * 
     * @return \UDA\Query\Upsert The UPSERT query builder
     */
    public function upsert(): \UDA\Query\Upsert
    {
        $query = $this->getDriver()->upsert();
        // Set the driver instance for constitutional delegation
        $this->setDriverInstance($query);
        return $query;
    }
    
    /**
     * 
     * @param object $query The query builder instance
     * @return void
     */
    private function setDriverInstance(object $query): void
    {
        $class = get_class($query);
        if (!isset(self::$reflectionCache[$class])) {
            self::$reflectionCache[$class] = new \ReflectionClass($query);
        }
        $reflection = self::$reflectionCache[$class];
        $property = $reflection->getProperty('driverInstance');
        $property->setAccessible(true);
        $property->setValue($query, $this->getDriver());
    }
}
