<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache
 * @author James Dornan <james.dornan@uda.example.com>
 * @license GPL-2.0-only
 * @link https://docs.uda.example.com/cache
 * @since 1.0.0
 */

namespace UDA;

use Throwable;
use UDA\Exception\ConnectionException;
use UDA\Exception\NotSupportedException;
use UDA\SQL\SqlMessage;

/*
 * Purpose: Own the entire runtime cache workflow for UDA in one place.
 *
 * This class is the cache tool. It decides nothing about policy on its own.
 * Config answers policy questions. Cache executes those answers.
 *
 * Public behavior is intentionally small:
 * - read()  : metadata-first cached read with executor fallback
 * - put()   : store metadata + payload
 * - touch() : update table mtimes after writes
 * - clear() : reset process-local static client/namespace maps (not remote key purge)
 * - flush() : delete cached payload, metadata, and table-mtime keys for a connection
 *
 * There is no has(). We do the operation and return null or a result.
 * There are no store interfaces. There is no setup object. There is no
 * caller-visible cache framework. This file owns the runtime cache behavior.
 */

/**
 * Static cache runtime keyed by connection name and query metadata.
 *
 * Cache owns backend client reuse and payload storage. It does not decide
 * whether a query is cacheable; Config supplies that policy.
 */
final class Cache
{
    /**
     * Cache format version.
     *
     * @var int
     */
    private const FORMAT_VERSION = 1;

    /**
     * In-process backend client cache keyed by connection name.
     *
     * @var array<string, object|null>
     */
    private static array $clients = [];

    /**
     * Cached namespace values keyed by connection name.
     *
     * @var array<string, string>
     */
    private static array $namespaces = [];

    /**
     * Serialization
     *
     * @var ?string i|p
     */
    private static ?string $serializer = null;

    /**
     * Perform a metadata-first cached read.
     * Behavior:
     * - If caching is disabled for the connection/tables, execute immediately.
     * - If metadata is absent or unusable, execute immediately.
     * - If metadata is usable and payload exists, return cached payload.
     *
     * @param string     $connectionName  Connection name.
     * @param SqlMessage $sql             SQL query.
     * @param string     $shape           Result shape name.
     *
     * @return mixed
     *
     * @throws Throwable If execution fails and stale fallback is unavailable.
     */
    public static function read(string $connectionName, SqlMessage $sql, string $shape = 'rows'): ?array
    {

        $state = self::state($connectionName);
        if ($state === null) {
            return null;
        }

        $key  = self::key($connectionName, $sql, $shape);
        $metadata = self::fetch($state['client'], 'm:' . $key);

        if (!is_array($metadata)) {
            return null;
        }

        $tables = $metadata['tables'] ?? [];

        if (!is_array($tables)) {
            return null;
        }

        if (self::isStale($connectionName, $tables, $metadata)) {
            return null;
        }

        $value = self::fetch($state['client'], $key);

        return is_array($value) ? $value : null;
    }

    /**
     * Store metadata and payload for a query result.
     *
     * @param string       $connectionName  Connection name.
     * @param SqlMessage   $sql             SQL message.
     * @param string|array $value           Result payload.
     * @param string       $shape           Result shape name.
     *
     * @return void
     *
     * @param array<int, string> $tables         Involved tables.
     */
    public static function put(
        string $connectionName,
        SqlMessage $sql,
        array $tables,
        array $value,
        string $shape = 'rows'
    ): void {
        $key = self::key($connectionName, $sql, $shape);
        self::putData($connectionName, $key, $value);
        self::putMetadata($connectionName, $key, $tables);
    }

    /**
     * Put data
     *
     * @param string $connectionName  Connection name.
     * @param string $key             Cache key.
     * @param array  $value           Value being stored.
     *
     * @return void
     */
    public static function putData(string $connectionName, string $key, array $value): void
    {
        $state = self::state($connectionName);

        if ($state === null) {
            return;
        }

        self::store($state, $key, $value, 3600);
    }

    /**
     * Store Metadta
     *
     * @param string $connectionName  Connection name.
     * @param string $key             Cache or plan-cache key.
     *
     * @return void
     *
     * @param array<int, string> $tables         Involved tables.
     */
    public static function putMetadata(string $connectionName, string $key, array $tables): void
    {
        $state = self::state($connectionName);

        if ($state === null) {
            return;
        }

        $metadata = [
            'ctime'   => time(),
            'tables'  => $tables
        ];
        self::store($state, 'm:'. $key, $metadata, 3600);
    }

    /**
     * Touch table mtimes after a successful write.
     *
     * @param string $connectionName  Connection name.
     *
     * @return void
     *
     * @param array<int, string> $tables         Touched tables.
     */
    public static function touch(string $connectionName, array $tables): void
    {
        $state = self::state($connectionName);

        if (empty($tables) || empty($state)) {
            return;
        }

        $now = time();

        foreach ($tables as $table) {
            self::store($state, self::tableMtimeKey($connectionName, $table), $now, 0);
        }
    }

    /**
     * Clear process-local static client handles and namespace cache for this PHP process.
     *
     * Does not delete payload or metadata keys from Redis, Memcached, or other remote stores.
     * Typical uses: test isolation, long-lived workers that must drop extension clients without exiting.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$clients = [];
        self::$namespaces = [];
    }

    /**
     * Delete cached payload, metadata, and table-mtime keys for a connection.
     *
     * Scope is the connection's configured namespace and connection name segment.
     * Does not call FLUSHDB or memcached flush-all on shared backends.
     *
     * @param string $connectionName  Connection name.
     *
     * @return void
     *
     * @throws NotSupportedException When the backend cannot delete by prefix.
     */
    public static function flush(string $connectionName): void
    {
        $state = self::state($connectionName);
        if ($state === null) {
            return;
        }

        match ($state['store']) {
            'array'     => self::flushArray($state['client'], $connectionName, $state['namespace']),
            'redis'     => self::flushRedis($state['client'], $connectionName, $state['namespace']),
            'memcached' => self::flushMemcached($state['client'], $connectionName, $state['namespace']),
            default     => throw new NotSupportedException(
                sprintf('Unsupported cache store "%s" for connection "%s"', $state['store'], $connectionName)
            ),
        };
    }

    /**
     * Determine whether a stored key belongs to a connection namespace scope.
     *
     * @param string $key             Stored cache key.
     * @param string $connectionName  Connection name.
     * @param string $namespace       Cache namespace.
     *
     * @return bool
     */
    private static function keyBelongsToConnection(string $key, string $connectionName, string $namespace): bool
    {
        $connection = strtolower($connectionName);

        if (str_starts_with($key, 'm:')) {
            return self::payloadKeyBelongsToConnection(substr($key, 2), $connectionName, $namespace);
        }

        if (str_starts_with($key, sprintf('t:%s|%s|', $namespace, $connection))) {
            return true;
        }

        return self::payloadKeyBelongsToConnection($key, $connectionName, $namespace);
    }

    /**
     * Determine whether a payload key belongs to a connection.
     *
     * @param string $key             Payload cache key.
     * @param string $connectionName  Connection name.
     * @param string $namespace       Cache namespace.
     *
     * @return bool
     */
    private static function payloadKeyBelongsToConnection(string $key, string $connectionName, string $namespace): bool
    {
        $parts = explode('|', $key, 5);
        if (count($parts) < 5) {
            return false;
        }

        [$keyNamespace, $version, $serializer, $connection] = $parts;

        return $keyNamespace === $namespace
            && $version === 'v' . self::FORMAT_VERSION
            && str_starts_with($serializer, 's:')
            && $connection === strtolower($connectionName);
    }

    /**
     * Delete matching keys from the in-process array store.
     *
     * @param object $client          Array cache client.
     * @param string $connectionName  Connection name.
     * @param string $namespace       Cache namespace.
     *
     * @return void
     */
    private static function flushArray(object $client, string $connectionName, string $namespace): void
    {
        if (!method_exists($client, 'deleteMatching')) {
            return;
        }

        $client->deleteMatching(
            static fn (string $key): bool => self::keyBelongsToConnection($key, $connectionName, $namespace)
        );
    }

    /**
     * Delete matching keys from Redis using SCAN.
     *
     * @param object $client          Redis client.
     * @param string $connectionName  Connection name.
     * @param string $namespace       Cache namespace.
     *
     * @return void
     */
    private static function flushRedis(object $client, string $connectionName, string $namespace): void
    {
        $connection = strtolower($connectionName);
        $patterns = [
            sprintf('%s|v%d|s:*|%s|*', $namespace, self::FORMAT_VERSION, $connection),
            sprintf('m:%s|v%d|s:*|%s|*', $namespace, self::FORMAT_VERSION, $connection),
            sprintf('t:%s|%s|*', $namespace, $connection),
        ];

        foreach ($patterns as $pattern) {
            $iterator = null;

            do {
                $keys = $client->scan($iterator, $pattern, 100);

                if (is_array($keys) && $keys !== []) {
                    $client->del($keys);
                }
            } while ($iterator > 0);
        }
    }

    /**
     * Delete matching keys from Memcached when prefix enumeration is available.
     *
     * @param object $client          Memcached client.
     * @param string $connectionName  Connection name.
     * @param string $namespace       Cache namespace.
     *
     * @return void
     *
     * @throws NotSupportedException When getAllKeys is unavailable.
     */
    private static function flushMemcached(object $client, string $connectionName, string $namespace): void
    {
        if (!method_exists($client, 'getAllKeys')) {
            throw new NotSupportedException(
                sprintf(
                    'Cache flush for memcached on connection "%s" requires getAllKeys support; use a dedicated instance or redis store.',
                    $connectionName
                )
            );
        }

        $keys = $client->getAllKeys();
        if ($keys === false) {
            throw new NotSupportedException(
                sprintf(
                    'Cache flush for memcached on connection "%s" failed to enumerate keys.',
                    $connectionName
                )
            );
        }

        foreach ($keys as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (self::keyBelongsToConnection($key, $connectionName, $namespace)) {
                $client->delete($key);
            }
        }
    }

    /**
     * Determine whether any tracked table has been updated since the entry was stored.
     *
     * @param string $connectionName  Connection name.
     *
     * @return bool
     *
     * @param array<int, string>   $tables         Normalized tables.
     * @param array<string, mixed> $metadata       Metadata record.
     */
    private static function isStale(
        string $connectionName,
        array $tables,
        array $metadata
    ): bool {
        $ctime = $metadata['ctime'];

        foreach ($tables as $table) {
            if (self::tableMtime($connectionName, $table) > $ctime) {
                return true;
            }
        }

        return false;
    }

    /**
     * Capture current table mtimes.
     *
     * @param string $connectionName  Connection name.
     *
     * @return array<string, int>
     *
     * @param array<int, string> $tables         Normalized tables.
     */
    private static function tableWriteTimes(string $connectionName, array $tables): array
    {
        $times = [];

        foreach ($tables as $table) {
            $times[$table] = self::tableMtime($connectionName, $table);
        }

        return $times;
    }

    /**
     * Read a table mtime from the backend.
     *
     * @param string $connectionName  Connection name.
     * @param string $table           Table name.
     *
     * @return int
     */
    private static function tableMtime(string $connectionName, string $table): int
    {
        $state = self::state($connectionName);
        if ($state === null) {
            return 0;
        }

        $value = self::fetch($state['client'], self::tableMtimeKey($connectionName, $table));

        return is_int($value) ? $value : (int) ($value ?? 0);
    }

    /**
     * Resolve the runtime backend state for a connection.
     *
     * @param string $connectionName  Connection name.
     *
     * @return array{client:object,store:string,namespace:string}|null
     *
     * @throws NotSupportedException If an unsupported cache store name is configured.
     */
    private static function state(string $connectionName): ?array
    {
        $store = strtolower(Config::cacheStore($connectionName));

        if ($store === '' || $store === 'off' || $store === 'none') {
            return null;
        }

        if (!array_key_exists($connectionName, self::$clients)) {
            self::$clients[$connectionName] = self::connect($connectionName, $store);
        }

        $client = self::$clients[$connectionName];
        if ($client === null) {
            return null;
        }

        if (!isset(self::$namespaces[$connectionName])) {
            self::$namespaces[$connectionName] = Config::cacheNamespace($connectionName);
        }

        return [
            'client'    => $client,
            'store'     => $store,
            'namespace' => self::$namespaces[$connectionName],
        ];
    }

    /**
     * Create a backend client.
     *
     * @param string $connectionName  Connection name.
     * @param string $store           Store name.
     *
     * @return object|null
     *
     * @throws NotSupportedException If an unsupported cache store is configured.
     */
    private static function connect(string $connectionName, string $store): ?object
    {
        if (self::$serializer === null) {
            self::$serializer = extension_loaded('igbinary') ? 'i' : 'p';
        }

        return match ($store) {
            'redis'     => self::connectRedis($connectionName),
            'memcached' => self::connectMemcached($connectionName),
            'array'     => self::connectArray(),
            default     => throw new NotSupportedException(
                sprintf('Unsupported cache store "%s" for connection "%s"', $store, $connectionName)
            ),
        };
    }

    /**
     * Connect to process-local array cache.
     *
     * @return object Cache client with get/set methods.
     */
    private static function connectArray(): object
    {
        return new class {
            /** @var array<string,array{value:mixed,expires:int}> */
            private array $items = [];

            /**
             * @param string $key  Cache key.
             *
             * @return mixed
             */
            public function get(string $key): mixed
            {
                if (!array_key_exists($key, $this->items)) {
                    return null;
                }

                $entry = $this->items[$key];
                if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
                    unset($this->items[$key]);
                    return null;
                }

                return $entry['value'];
            }

            /**
             * @param string $key    Cache key.
             * @param mixed  $value  Value to store.
             * @param int    $ttl    TTL seconds.
             *
             * @return bool True when stored.
             */
            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                $this->items[$key] = [
                    'value'   => $value,
                    'expires' => $ttl > 0 ? time() + $ttl : 0,
                ];

                return true;
            }

            /**
             * @param callable(string): bool $matcher  Key predicate.
             *
             * @return void
             */
            public function deleteMatching(callable $matcher): void
            {
                foreach (array_keys($this->items) as $key) {
                    if ($matcher($key)) {
                        unset($this->items[$key]);
                    }
                }
            }
        };
    }

    /**
     * Connect to Redis.
     *
     * @param string $connectionName  Connection name.
     *
     * @return object
     *
     * @throws NotSupportedException If an unsupported cache store is configured.
     * @throws ConnectionException   If the operation fails.
     */
    private static function connectRedis(string $connectionName): object
    {
        if (!extension_loaded('redis')) {
            throw new NotSupportedException(
                sprintf('Unsupported cache store "redis" for connection "%s", Redis extension not loaded.', $connectionName)
            );
        }

        $redisClass = 'Redis';
        $client  = new $redisClass();
        $host    = Config::cacheHost($connectionName);
        $port    = Config::cachePort($connectionName);
        $timeout = Config::cacheTimeout($connectionName);

        $ok = $client->connect($host, $port, $timeout);

        if ($ok !== true) {
            throw new ConnectionException(
                sprintf(
                    'Cache store "redis" configured for connection "%s", but connection to %s:%d failed.',
                    $connectionName,
                    $host,
                    $port
                )
            );
        }

        $client->setOption(
            constant('Redis::OPT_SERIALIZER'),
            self::$serializer === 'i'
                ? constant('Redis::SERIALIZER_IGBINARY')
                : constant('Redis::SERIALIZER_PHP')
        );

        $database = Config::cacheDatabase($connectionName);
        if ($database > 0) {
            $client->select($database);
        }

        return $client;
    }

    /**
     * Connect to Memcached.
     *
     * @param string $connectionName  Connection name.
     *
     * @return object
     *
     * @throws ConnectionException   If the operation fails.
     * @throws NotSupportedException If the operation fails.
     */
    private static function connectMemcached(string $connectionName): object
    {
        if (!extension_loaded('memcached')) {
            throw new NotSupportedException(
                sprintf('Unsupported cache store "memcached" for connection "%s", Memcached extension not loaded.', $connectionName)
            );
        }

        $memcachedClass = 'Memcached';
        $client = new $memcachedClass();
        $host    = Config::cacheHost($connectionName);
        $port    = Config::cachePort($connectionName);

        $client->addServer($host, $port);
        $ok = $client->getVersion();

        if ($ok === false) {
            throw new ConnectionException(
                sprintf(
                    'Cache store "memcached" configured for connection "%s", but connection to %s:%d failed.',
                    $connectionName,
                    $host,
                    $port
                )
            );
        }

        $client->setOption(
            constant('Memcached::OPT_SERIALIZER'),
            self::$serializer === 'i'
                ? constant('Memcached::SERIALIZER_IGBINARY')
                : constant('Memcached::SERIALIZER_PHP')
        );

        return $client;
    }

    /**
     * Fetch a key from the backend.
     *
     * @param object $client  Redis or Memcached
     * @param string $key     Cache key.
     *
     * @return mixed
     */
    private static function fetch(object $client, string $key): mixed
    {
        return $client->get($key);
    }

    /**
     * Store a key in the backend.
     * A TTL of 0 means no expiration.
     *
     * @param array{client:object,store:string,namespace:string} $state  Runtime store state.
     * @param string                                             $key    Cache key.
     * @param mixed                                              $value  Value to store.
     * @param int                                                $ttl    TTL seconds.
     *
     * @return void
     */
    private static function store(array $state, string $key, mixed $value, int $ttl): void
    {
        $client = $state['client'];

        if ($ttl > 0) {
            $client->set($key, $value, $ttl);
            return;
        }

        $client->set($key, $value);
    }

    /**
     * Build the cache key.
     *
     * @param string     $connectionName  Connection name.
     * @param SqlMessage $sql             SQL message.
     * @param string     $shape           Result shape name.
     *
     * @return string
     */
    private static function key(
        string $connectionName,
        SqlMessage $sql,
        string $shape = 'rows'
    ): string {
        $namespace = self::$namespaces[$connectionName] ?? Config::cacheNamespace($connectionName);

        $hash = hash(
            'sha256',
            $shape . "\n" . $sql->getQuery() . "\n" . self::stableParamEncoding($sql->getParams())
        );

        return sprintf(
            '%s|v%d|s:%s|%s|%s',
            $namespace,
            self::FORMAT_VERSION,
	    self::$serializer,
            strtolower($connectionName),
            $hash
        );
    }

    /**
     * Build table mtime key.
     *
     * @param string $connectionName  Connection name.
     * @param string $table           Table name.
     *
     * @return string
     */
    private static function tableMtimeKey(string $connectionName, string $table): string
    {
        $namespace = self::$namespaces[$connectionName] ?? Config::cacheNamespace($connectionName);

        return sprintf(
            't:%s|%s|%s',
            $namespace,
            strtolower($connectionName),
            strtolower($table)
        );
    }

    /**
     * Produce stable param encoding for cache keys.
     *
     * @return string
     *
     * @param array<string, mixed> $params Query params.
     */
    private static function stableParamEncoding(array $params): string
    {
        $json = json_encode(
            self::normalizeParams($params),
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return ($json === false) ? '' : $json;
    }

    /**
     * Normalize params recursively for deterministic encoding.
     *
     * @return array<string|int, mixed>
     *
     * @param array<string|int, mixed> $params Params.
     */
    private static function normalizeParams(array $params): array
    {
        ksort($params);

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = self::normalizeParams($value);
            }
        }

        return $params;
    }
}
