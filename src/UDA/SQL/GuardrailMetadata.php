<?php

declare(strict_types=1);

namespace UDA\SQL;

use InvalidArgumentException;

final class GuardrailMetadata
{
    /**
     * @var string[]
     */
    private const STATEMENT_TYPES = ['select', 'insert', 'update', 'delete', 'upsert', 'raw'];

    /**
     * @return array{0:string,1:bool,2:bool,3:bool}
     */
    public static function normalize(array $metadata): array
    {
        return [
            self::normalizeType((string) ($metadata['statementType'] ?? 'raw')),
            (bool) ($metadata['hasWhere'] ?? false),
            (bool) ($metadata['hasLimit'] ?? false),
            (bool) ($metadata['unsafe'] ?? false),
        ];
    }

    /**
     * @return array{statementType:string,hasWhere:bool,hasLimit:bool,unsafe:bool}
     */
    public static function package(string $statementType, bool $hasWhere, bool $hasLimit, bool $unsafe): array
    {
        return [
            'statementType' => self::normalizeType($statementType),
            'hasWhere' => $hasWhere,
            'hasLimit' => $hasLimit,
            'unsafe' => $unsafe,
        ];
    }

    public static function normalizeType(string $statementType): string
    {
        $type = strtolower($statementType);

        if (!in_array($type, self::STATEMENT_TYPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid SQL statement type "%s". Allowed: %s',
                $statementType,
                implode(', ', self::STATEMENT_TYPES)
            ));
        }

        return $type;
    }
}
