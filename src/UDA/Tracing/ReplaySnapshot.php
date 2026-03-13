<?php

declare(strict_types=1);

namespace UDA\Tracing;

final class ReplaySnapshot
{
    public function __construct(
        public readonly string $connection,
        public readonly string $dialect,
        public readonly string $operation,
        public readonly string $sql,
        /** @var array<string,mixed> */
        public readonly array $params,
        /** @var array<int,string> */
        public readonly array $tables,
        public readonly float $durationMs,
        public readonly int $rowCount,
        public readonly int $timestamp,
        public readonly bool $sqlTruncated = false,
        public readonly bool $parametersTruncated = false,
        /** @var array<string,mixed> */
        public readonly array $metadata = []
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connection: (string) ($data['connection'] ?? ''),
            dialect: (string) ($data['dialect'] ?? ''),
            operation: (string) ($data['operation'] ?? ''),
            sql: (string) ($data['sql'] ?? ''),
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            tables: self::normalizeTables($data['tables'] ?? []),
            durationMs: (float) ($data['duration'] ?? $data['durationMs'] ?? 0.0),
            rowCount: (int) ($data['rowCount'] ?? 0),
            timestamp: (int) ($data['timestamp'] ?? time()),
            sqlTruncated: (bool) ($data['sqlTruncated'] ?? false),
            parametersTruncated: (bool) ($data['parametersTruncated'] ?? false),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'dialect' => $this->dialect,
            'operation' => $this->operation,
            'sql' => $this->sql,
            'params' => $this->params,
            'tables' => $this->tables,
            'duration' => $this->durationMs,
            'rowCount' => $this->rowCount,
            'timestamp' => $this->timestamp,
            'sqlTruncated' => $this->sqlTruncated,
            'parametersTruncated' => $this->parametersTruncated,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param mixed $tables
     * @return array<int,string>
     */
    private static function normalizeTables(mixed $tables): array
    {
        if (!is_array($tables)) {
            return [];
        }

        return array_values(array_map(static fn ($table): string => (string) $table, $tables));
    }
}
