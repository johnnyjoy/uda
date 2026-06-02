<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Exception
 * @author James Dornan <james.dornan@uda.example.com>
 * @license MIT
 * @link https://docs.uda.example.com/exception/query
 * @since 1.0.0
 */

/*
 * Purpose: Specialized exception for query-related errors in UDA.
 */

namespace UDA\Exception;

use PDOException;
use RuntimeException;
use Throwable;

/**
 * Specialized exception for query-related error scenarios.
 *
 * Exposes stable fields for API-layer mapping: SQLSTATE, driver code, category.
 */
class QueryException extends RuntimeException
{
    public const CATEGORY_EXECUTION = 'execution';

    public const CATEGORY_GUARDRAIL = 'guardrail';

    public const CATEGORY_CONNECTION = 'connection';

    public const CATEGORY_CONSTRAINT = 'constraint';

    public const CATEGORY_SYNTAX = 'syntax';

    public const CATEGORY_UNSUPPORTED = 'unsupported';

    public const CATEGORY_BINDING = 'binding';

    private string $category;

    private ?string $sqlState;

    private ?string $driverCode;

    /**
     * @param string          $message    Human-readable error message.
     * @param int             $code       Optional exception code.
     * @param Throwable|null  $previous   Optional previous throwable.
     * @param string          $category   Stable category for API mapping.
     * @param string|null     $sqlState   SQLSTATE when available.
     * @param string|null     $driverCode Driver-specific error code when available.
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        string $category = self::CATEGORY_EXECUTION,
        ?string $sqlState = null,
        ?string $driverCode = null,
    ) {
        if ($previous instanceof PDOException) {
            [$pdoState, $pdoCode] = self::extractPdoErrorInfo($previous);
            $sqlState ??= $pdoState;
            $driverCode ??= $pdoCode;
        }

        if ($category === self::CATEGORY_EXECUTION && $sqlState !== null && $sqlState !== '') {
            $category = self::categorizeSqlState($sqlState);
        }

        parent::__construct($message, $code, $previous);

        $this->category = $category;
        $this->sqlState = $sqlState;
        $this->driverCode = $driverCode;
    }

    /**
     * Guardrail or policy violation (positional params, missing hints, unbounded writes).
     */
    public static function guardrail(string $message): self
    {
        return new self($message, category: self::CATEGORY_GUARDRAIL);
    }

    /**
     * Engine or dialect capability not available.
     */
    public static function unsupported(string $message): self
    {
        return new self($message, category: self::CATEGORY_UNSUPPORTED);
    }

    /**
     * Wrap a PDO failure with prefix context and extracted SQLSTATE.
     */
    public static function fromPdo(string $prefix, PDOException $ex): self
    {
        return new self($prefix . ': ' . $ex->getMessage(), 0, $ex);
    }

    /**
     * Stable category for HTTP/status mapping.
     */
    public function category(): string
    {
        return $this->category;
    }

    /**
     * SQLSTATE when the driver reported one.
     */
    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    /**
     * Driver-specific error code when available.
     */
    public function driverCode(): ?string
    {
        return $this->driverCode;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private static function extractPdoErrorInfo(PDOException $ex): array
    {
        $info = $ex->errorInfo ?? null;

        if (!is_array($info)) {
            return [null, null];
        }

        $sqlState = isset($info[0]) && is_string($info[0]) && $info[0] !== '' ? $info[0] : null;
        $driverCode = isset($info[1]) ? (string) $info[1] : null;

        return [$sqlState, $driverCode];
    }

    private static function categorizeSqlState(string $sqlState): string
    {
        $class = strlen($sqlState) >= 2 ? substr($sqlState, 0, 2) : '';

        return match ($class) {
            '08' => self::CATEGORY_CONNECTION,
            '23' => self::CATEGORY_CONSTRAINT,
            '42' => self::CATEGORY_SYNTAX,
            default => self::CATEGORY_EXECUTION,
        };
    }
}
