<?php

declare(strict_types=1);

namespace UDA\Safety;

final class GuardrailConfig
{
    /**
     * @param list<string> $requireLimitOnWritesExcept
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly bool $productionMode,
        public readonly bool $updateRequiresWhere,
        public readonly bool $deleteRequiresWhere,
        public readonly bool $requireLimitOnWrites,
        public readonly array $requireLimitOnWritesExcept,
        public readonly bool $truncateBlocked,
    ) {
    }

    public static function defaults(): self
    {
        return new self(false, false, true, true, false, [], false);
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = self::defaults()->toArray();
        $data = $defaults;

        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $config)) {
                $data[$key] = $config[$key];
            }
        }

        $except = $data['requireLimitOnWritesExcept'];
        if (!is_array($except)) {
            $except = [];
        }

        $normalizedExcept = [];
        foreach ($except as $table) {
            $normalized = strtolower(trim((string) $table));
            if ($normalized === '') {
                continue;
            }

            $normalizedExcept[$normalized] = true;
        }

        return new self(
            (bool) ($data['enabled'] ?? false),
            (bool) ($data['productionMode'] ?? false),
            (bool) ($data['updateRequiresWhere'] ?? true),
            (bool) ($data['deleteRequiresWhere'] ?? true),
            (bool) ($data['requireLimitOnWrites'] ?? false),
            array_keys($normalizedExcept),
            (bool) ($data['truncateBlocked'] ?? false),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'productionMode' => $this->productionMode,
            'updateRequiresWhere' => $this->updateRequiresWhere,
            'deleteRequiresWhere' => $this->deleteRequiresWhere,
            'requireLimitOnWrites' => $this->requireLimitOnWrites,
            'requireLimitOnWritesExcept' => $this->requireLimitOnWritesExcept,
            'truncateBlocked' => $this->truncateBlocked,
        ];
    }

    public function requiresWhere(string $statementType): bool
    {
        return match (strtolower($statementType)) {
            'update' => $this->updateRequiresWhere,
            'delete' => $this->deleteRequiresWhere,
            default => false,
        };
    }

    public function requiresLimitOnWrites(?string $tableName = null): bool
    {
        if (!$this->requireLimitOnWrites) {
            return false;
        }

        if ($tableName === null) {
            return true;
        }

        return !$this->isTableLimitExempt($tableName);
    }

    public function isTableLimitExempt(string $tableName): bool
    {
        return in_array(strtolower($tableName), $this->requireLimitOnWritesExcept, true);
    }
}
