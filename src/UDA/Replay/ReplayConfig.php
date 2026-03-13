<?php

declare(strict_types=1);

namespace UDA\Replay;

final class ReplayConfig
{
    /**
     * @param array<int,string> $maskParameters
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $backend,
        public readonly string $directory,
        public readonly ?int $maxSqlLength,
        public readonly ?int $maxParamSize,
        public readonly array $maskParameters = []
    ) {
    }

    public static function defaults(): self
    {
        return new self(false, 'file', 'storage/replay', null, null, []);
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $mask = $config['maskParameters'] ?? [];

        if (!is_array($mask)) {
            $mask = [];
        }

        $mask = array_values(array_map(static fn ($value): string => (string) $value, $mask));

        $directory = (string) ($config['directory'] ?? 'storage/replay');

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            backend: (string) ($config['backend'] ?? 'file'),
            directory: $directory === '' ? 'storage/replay' : $directory,
            maxSqlLength: isset($config['maxSqlLength']) ? (int) $config['maxSqlLength'] : null,
            maxParamSize: isset($config['maxParamSize']) ? (int) $config['maxParamSize'] : null,
            maskParameters: $mask
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'backend' => $this->backend,
            'directory' => $this->directory,
            'maxSqlLength' => $this->maxSqlLength,
            'maxParamSize' => $this->maxParamSize,
            'maskParameters' => $this->maskParameters,
        ];
    }
}
