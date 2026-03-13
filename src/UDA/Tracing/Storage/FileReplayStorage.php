<?php

declare(strict_types=1);

namespace UDA\Tracing\Storage;

use RuntimeException;
use UDA\Tracing\ReplaySnapshot;
use UDA\Tracing\ReplayStorageInterface;

final class FileReplayStorage implements ReplayStorageInterface
{
    private ?\Closure $clock;

    private ?\Closure $jsonEncoder = null;

    private ?\Closure $directoryResolver = null;

    private ?\Closure $handle = null;

    private ?string $currentDate = null;

    public function __construct(
        private readonly string $directory,
        private readonly ?int $maxSqlLength = null,
        private readonly ?int $maxParamSize = null,
        private readonly bool $enabled = true,
        ?callable $clock = null
    ) {
        $this->clock = $clock !== null ? $clock(...) : static function (): int {
            return time();
        };
    }

    public function persist(ReplaySnapshot $snapshot): void
    {
        if (!$this->enabled) {
            return;
        }

        $timestamp = ($this->clock)();
        $date = gmdate('Y-m-d', $timestamp);
        $handle = $this->openHandle($date);

        [$snapshot, $jsonPayload] = $this->prepareSnapshot($snapshot);

        $line = $jsonPayload . "\n";

        if ($handle === null) {
            return;
        }

        $fh = ($handle)();

        if (!is_resource($fh)) {
            return;
        }

        if (flock($fh, LOCK_EX)) {
            fwrite($fh, $line);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
    }

    public function flush(): void
    {
        if ($this->handle !== null) {
            $fh = ($this->handle)();

            if (is_resource($fh)) {
                fflush($fh);
            }
        }
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            $fh = ($this->handle)();

            if (is_resource($fh)) {
                fflush($fh);
                fclose($fh);
            }
        }

        $this->handle = null;
        $this->currentDate = null;
    }

    /**
     * @return array{0:ReplaySnapshot,1:string}
     */
    private function prepareSnapshot(ReplaySnapshot $snapshot): array
    {
        $sql = $snapshot->sql;
        $params = $snapshot->params;
        $sqlTruncated = $snapshot->sqlTruncated;
        $paramsTruncated = $snapshot->parametersTruncated;

        if ($this->maxSqlLength !== null && mb_strlen($sql) > $this->maxSqlLength) {
            $sql = mb_substr($sql, 0, $this->maxSqlLength);
            $sqlTruncated = true;
        }

        if ($this->maxParamSize !== null) {
            $json = json_encode($params, JSON_UNESCAPED_UNICODE);

            if ($json !== false && strlen($json) > $this->maxParamSize) {
                $params = ['__truncated__' => true];
                $paramsTruncated = true;
            }
        }

        $payloadSnapshot = new ReplaySnapshot(
            connection: $snapshot->connection,
            dialect: $snapshot->dialect,
            operation: $snapshot->operation,
            sql: $sql,
            params: $params,
            tables: $snapshot->tables,
            durationMs: $snapshot->durationMs,
            rowCount: $snapshot->rowCount,
            timestamp: $snapshot->timestamp,
            sqlTruncated: $sqlTruncated,
            parametersTruncated: $paramsTruncated,
            metadata: $snapshot->metadata
        );

        $jsonPayload = json_encode($payloadSnapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return [$payloadSnapshot, $jsonPayload];
    }

    private function openHandle(string $date): ?\Closure
    {
        if ($this->handle !== null && $this->currentDate === $date) {
            return $this->handle;
        }

        $this->close();

        $this->ensureDirectory();

        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sprintf('queries-%s.ndjson', $date);
        $fh = @fopen($path, 'ab');

        if ($fh === false) {
            throw new RuntimeException('Unable to open replay storage file: ' . $path);
        }

        $this->currentDate = $date;
        $this->handle = static fn () => $fh;

        return $this->handle;
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!@mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Unable to create replay directory: ' . $this->directory);
        }
    }
}
