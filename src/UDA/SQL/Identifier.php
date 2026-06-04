<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage SQL
 * @license MIT
 * @link https://github.com/johnnyjoy/uda/blob/master/docs/architecture.md
 * @since 1.0.0
 */

/*
 * Purpose: Database identifier validator and quoter with security validation.
 */

namespace UDA\SQL;

use UDA\Driver;
use UDA\Exception\QueryException;

/**
 * Identifier validation with engine-based quoting
 */
class Identifier
{
    private string $name;

    /**
     * Create a new identifier
     *
     * @param string ...$segments  The identifier segments
     *
     * @throws InvalidIdentifierException If the identifier is invalid
     */
    public function __construct(string ...$segments)
    {
        $this->name = $this->validateAndJoinSegments($segments);
    }

    /**
     * Get the identifier name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Validate and quote an identifier for an engine in one step.
     *
     * Shared by the query builders and dialect state objects so identifier
     * quoting has a single validated implementation.
     *
     * @param string $identifier  Identifier to validate and quote.
     * @param string $engine      Engine key (sqlite, mariadb, pgsql, etc.).
     *
     * @return string The quoted identifier.
     *
     * @throws QueryException If the identifier is invalid.
     */
    public static function quoteFor(string $identifier, string $engine): string
    {
        try {
            return (new self($identifier))->quoted($engine);
        } catch (\Throwable $ex) {
            throw new QueryException('Invalid identifier: ' . $identifier, 0, $ex);
        }
    }

    /**
     * Get the quoted identifier for a specific engine.
     *
     * @param string $engine  Engine key (sqlite, mariadb, pgsql, etc.)
     *
     * @return string The quoted identifier
     */
    public function quoted(string $engine): string
    {
        $parts = explode('.', $this->name);
        $quoted = [];

        foreach ($parts as $part) {
            $quoted[] = $this->quoteSegment($part, $engine);
        }

        return implode('.', $quoted);
    }

    /**
     * Validate and join identifier segments
     *
     * @param array $segments  The identifier segments
     *
     * @return string The joined identifier
     *
     * @throws InvalidIdentifierException If any segment is invalid
     */
    private function validateAndJoinSegments(array $segments): string
    {
        if (empty($segments)) {
            throw new InvalidIdentifierException('Identifier cannot be empty');
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new InvalidIdentifierException('Identifier segments cannot be empty');
            }
        }

        $name = implode('.', $segments);

        if (!$this->isValidIdentifier($name)) {
            throw new InvalidIdentifierException("Invalid identifier: {$name}");
        }

        return $name;
    }

    /**
     * Check if an identifier is valid
     *
     * @param string $identifier  The identifier to check
     *
     * @return bool True if valid, false otherwise
     */
    private function isValidIdentifier(string $identifier): bool
    {
        // Check for dangerous keywords
        $dangerousKeywords = ['DROP', 'DELETE', 'INSERT', 'UPDATE', 'CREATE', 'ALTER', 'TRUNCATE'];
        $upperIdentifier = strtoupper($identifier);

        foreach ($dangerousKeywords as $keyword) {
            if (str_contains($upperIdentifier, $keyword)) {
                return false;
            }
        }

        // Check for SQL injection attempts and invalid characters
        if (str_contains($identifier, '--') || str_contains($identifier, '/*') || str_contains($identifier, '*/')) {
            return false;
        }

        // Do not use [/] beside [*] in one class — PHP treats that as an invalid range.
        if (preg_match('/[;\'"\\\\]/', $identifier)) {
            return false;
        }

        if (str_contains($identifier, '/') || str_contains($identifier, '*')) {
            return false;
        }

        // Basic alphanumeric and underscore check with dots for schema.table notation
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $identifier);
    }

    /**
     * Quote a segment for a specific engine.
     *
     * @param string $segment  The segment to quote
     * @param string $engine   Engine key
     *
     * @return string The quoted segment
     */
    private function quoteSegment(string $segment, string $engine): string
    {
        return Driver::quoteIdentifier($engine, $segment);
    }
}
