<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache
 */

namespace UDA;

/*
 * Purpose: Connection-scoped cache controller implementing metadata-first caching for drivers.
 */

use Throwable;
use UDA\Cache\Serializer\Serializer;
use UDA\Cache\Setup;
use UDA\Cache\Store\CacheStoreInterface;
use UDA\Cache\TableWriteTrackerInterface;
use UDA\SQL\SqlMessage;

final class Cache
{
    private string $connectionName;
    private ?CacheStoreInterface $store = null;
    private ?TableWriteTrackerInterface $tracker = null;
    private Serializer $serializer;
    private string $namespace = 'UDA';
    /** @var array{ttlSeconds:int,minIntervalSeconds:int,allowStaleOnError:bool,maxStaleSeconds:int,disabled?:bool} */
    private array $defaultPolicy;
    /** @var array<string, array> */
    private array $tablePolicies;
    private int $formatVersion = 1;
    private bool $lastReadHit = false;

    public static function fromSetup(string $connectionName, ?Setup $setup = null): self
    {
        return new self($connectionName, $setup);
    }

    public function __construct(string $connectionName, ?Setup $setup = null)
    {
        $this->connectionName = $connectionName;
        $this->serializer = new Serializer();
        $this->defaultPolicy = [
            'ttlSeconds' => 0,
            'minIntervalSeconds' => 0,
            'allowStaleOnError' => false,
            'maxStaleSeconds' => 0,
            'disabled' => true,
        ];
        $this->tablePolicies = [];

        if ($setup === null) {
            return;
        }

        $this->store = $setup->store();
        $this->tracker = $setup->tracker();
        $this->serializer = $setup->serializer();
        $this->namespace = $setup->namespace();
        $this->defaultPolicy = $setup->defaultPolicy();
        $this->tablePolicies = $setup->tablePolicies();
        $this->formatVersion = $setup->formatVersion();
    }

    public function isEnabled(): bool
    {
        return $this->store !== null;
    }

    /**
     * Metadata-first cache read.
     *
     * @param callable():mixed         $executor    Database execution closure
     * @param callable(Throwable):bool $isTransient Transient detector
     */
    public function read(SqlMessage $sql, array $tables, callable $executor, callable $isTransient): mixed
    {
        $this->lastReadHit = false;

        if (!$this->isEnabled()) {
            return $executor();
        }

        $normalizedTables = $this->normalizeTables($tables);
        $policy = $this->resolvePolicy($normalizedTables);

        if (($policy['ttlSeconds'] ?? 0) <= 0 || !empty($policy['disabled'])) {
            return $executor();
        }

        $rootKey = $this->buildRootKey($sql, $normalizedTables);
        $state = $this->evaluateMetadata($rootKey, $normalizedTables, $policy);

        if ($state['useCache']) {
            $this->lastReadHit = true;
            return $state['payload'];
        }

        try {
            $result = $executor();
            $this->lastReadHit = false;
            $this->storeEntry($rootKey, $sql, $result, $normalizedTables, $policy);

            return $result;
        } catch (Throwable $e) {
            if ($state['stale'] && $isTransient($e)) {
                $payload = $this->fetchPayload($rootKey);

                if ($payload !== null) {
                    $this->lastReadHit = true;
                    return $payload;
                }
            }

            throw $e;
        }
    }

    public function touchTables(array $tables): void
    {
        if ($this->tracker === null) {
            return;
        }

        foreach ($this->normalizeTables($tables) as $table) {
            $this->tracker->touch($this->connectionName, $table);
        }
    }

    private function normalizeTables(array $tables): array
    {
        $normalized = [];

        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                continue;
            }
            $normalized[] = strtolower($table);
        }

        sort($normalized);

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{useCache:bool,payload:mixed,stale:bool}
     */
    private function evaluateMetadata(string $rootKey, array $tables, array $policy): array
    {
        $metadata = $this->fetchMetadata($rootKey);

        if ($metadata === null) {
            return ['useCache' => false, 'payload' => null, 'stale' => false];
        }

        $now = time();
        $createdAt = (int) ($metadata['createdAt'] ?? $now);
        $age = max(0, $now - $createdAt);

        if (($policy['minIntervalSeconds'] ?? 0) > 0 && $age < $policy['minIntervalSeconds']) {
            $payload = $this->fetchPayload($rootKey);

            return ['useCache' => $payload !== null, 'payload' => $payload, 'stale' => false];
        }

        if ($age > $policy['ttlSeconds']) {
            return ['useCache' => false, 'payload' => null, 'stale' => $this->staleAllowed($age, $policy)];
        }

        if ($this->tablesInvalidated($tables, $metadata)) {
            return ['useCache' => false, 'payload' => null, 'stale' => $this->staleAllowed($age, $policy)];
        }

        $payload = $this->fetchPayload($rootKey);

        return ['useCache' => $payload !== null, 'payload' => $payload, 'stale' => false];
    }

    private function staleAllowed(int $age, array $policy): bool
    {
        if (empty($policy['allowStaleOnError'])) {
            return false;
        }

        $max = $policy['ttlSeconds'] + ($policy['maxStaleSeconds'] ?? 0);

        return $age <= $max;
    }

    private function tablesInvalidated(array $tables, array $metadata): bool
    {
        if ($this->tracker === null || $tables === []) {
            return false;
        }

        $recorded = $metadata['tableWriteTimes'] ?? [];

        foreach ($tables as $table) {
            $previous = $recorded[$table] ?? 0;
            $current = $this->tracker->lastTouched($this->connectionName, $table) ?? 0;

            if ($current > $previous) {
                return true;
            }
        }

        return false;
    }

    private function storeEntry(string $rootKey, SqlMessage $sql, mixed $result, array $tables, array $policy): void
    {
        if ($this->store === null) {
            return;
        }

        $metadata = [
            'createdAt' => time(),
            'ttlSeconds' => $policy['ttlSeconds'],
            'minIntervalSeconds' => $policy['minIntervalSeconds'],
            'allowStaleOnError' => $policy['allowStaleOnError'],
            'maxStaleSeconds' => $policy['maxStaleSeconds'],
            'tables' => $tables,
            'tableWriteTimes' => $this->captureTableTimes($tables),
        ];

        $ttl = $policy['ttlSeconds'] + $policy['maxStaleSeconds'];
        $this->store->store($this->metadataKey($rootKey), json_encode($metadata), $ttl);
        $this->store->store($this->payloadKey($rootKey), $this->serializer->encode($result), $ttl);
    }

    private function captureTableTimes(array $tables): array
    {
        if ($this->tracker === null) {
            return [];
        }

        $times = [];

        foreach ($tables as $table) {
            $times[$table] = $this->tracker->lastTouched($this->connectionName, $table) ?? 0;
        }

        return $times;
    }

    private function fetchMetadata(string $rootKey): ?array
    {
        if ($this->store === null) {
            return null;
        }

        $value = $this->store->fetch($this->metadataKey($rootKey));

        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function fetchPayload(string $rootKey): mixed
    {
        if ($this->store === null) {
            return null;
        }

        $payload = $this->store->fetch($this->payloadKey($rootKey));

        if ($payload === null) {
            return null;
        }

        return $this->serializer->decode($payload);
    }

    private function metadataKey(string $rootKey): string
    {
        return 'm:' . $rootKey;
    }

    private function payloadKey(string $rootKey): string
    {
        return 'r:' . $rootKey;
    }

    private function buildRootKey(SqlMessage $sql, array $tables): string
    {
        $tableSegment = $tables === [] ? 'no-table' : implode('+', $tables);

        if (strlen($tableSegment) > 120) {
            $tableSegment = 't:' . hash('sha256', $tableSegment);
        }

        $hash = hash('sha256', $sql->getQuery() . "\n" . $this->stableParamEncoding($sql->getParams()));

        return sprintf(
            '%s|%s|v%d|%s|%s|%s',
            $this->namespace,
            $this->serializer->id(),
            $this->formatVersion,
            strtolower($this->connectionName),
            $tableSegment,
            $hash
        );
    }

    private function stableParamEncoding(array $params): string
    {
        $normalized = $this->normalizeParams($params);

        return json_encode($normalized, JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalizeParams(array $params): array
    {
        ksort($params);

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->normalizeParams($value);
            }
        }

        return $params;
    }

    /**
     * @param array $tables Normalized tables
     */
    private function resolvePolicy(array $tables): array
    {
        $policy = $this->defaultPolicy;
        $policy['disabled'] = $policy['disabled'] ?? false;

        foreach ($tables as $table) {
            $tablePolicy = $this->tablePolicies[$table] ?? null;

            if ($tablePolicy === null) {
                continue;
            }

            if (!empty($tablePolicy['disable'])) {
                $policy['disabled'] = true;
                break;
            }

            if (isset($tablePolicy['ttlSeconds'])) {
                $policy['ttlSeconds'] = min($policy['ttlSeconds'], (int) $tablePolicy['ttlSeconds']);
            }

            if (isset($tablePolicy['minIntervalSeconds'])) {
                $policy['minIntervalSeconds'] = max($policy['minIntervalSeconds'], (int) $tablePolicy['minIntervalSeconds']);
            }

            if (isset($tablePolicy['allowStaleOnError'])) {
                $policy['allowStaleOnError'] = $policy['allowStaleOnError'] && (bool) $tablePolicy['allowStaleOnError'];
            }

            if (isset($tablePolicy['maxStaleSeconds'])) {
                $policy['maxStaleSeconds'] = min($policy['maxStaleSeconds'], (int) $tablePolicy['maxStaleSeconds']);
            }
        }

        return $policy;
    }

    public function consumeLastReadHit(): bool
    {
        $hit = $this->lastReadHit;
        $this->lastReadHit = false;

        return $hit;
    }
}
