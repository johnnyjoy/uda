<?php

declare(strict_types=1);

/**
 * @license MIT
 */

namespace UDA\SQL;

use InvalidArgumentException;

/*
 * Purpose: Normalizes SQL guardrail metadata attached to compiled statements.
 *
 * GuardrailMetadata keeps safety flags in one predictable shape so Driver and
 * tooling can reason about statement type, WHERE/LIMIT presence, and unsafe
 * builder operations.
 */

/**
 * Utility for packaging and validating guardrail metadata.
 */
final class GuardrailMetadata
{
    /**
     * @var string[]
     */
    private const STATEMENT_TYPES = ['select', 'insert', 'update', 'delete', 'upsert', 'raw'];

    /**
     * Normalize.
     *
     * @param array $metadata  Guardrail and cache metadata.
     *
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
     * Package.
     *
     * @param string $statementType  Statement type.
     * @param bool   $hasWhere       Whether the statement has a WHERE clause.
     * @param bool   $hasLimit       Whether the statement has a LIMIT clause.
     * @param bool   $unsafe         Whether guardrails were bypassed.
     *
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

    /**
     * Normalize and validate a statement type label.
     *
     * @param string $statementType  Statement type.
     *
     * @return string String result.
     *
     * @throws InvalidArgumentException If the operation fails.
     */
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
