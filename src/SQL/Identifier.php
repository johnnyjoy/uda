<?php

declare(strict_types=1);

/** @purpose UDA\SQL\Identifier: Add detailed purpose here */

namespace UDA\SQL;

/**
 * Identifier validation with driver-based quoting
 */
class Identifier
{
    private string $name;

    /**
     * Create a new identifier
     *
     * @param string ...$segments The identifier segments
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
     * Get the quoted identifier for a specific driver
     *
     * @param string $driver The database driver (sqlite, mysql, pgsql, etc.)
     * @return string The quoted identifier
     */
    public function quoted(string $driver): string
    {
        $parts = explode('.', $this->name);

        return implode('.', array_map(
            fn(string $part): string => $this->quoteSegment($part, $driver),
            $parts
        ));
    }

    /**
     * Validate and join identifier segments
     *
     * @param array $segments The identifier segments
     * @return string The joined identifier
     * @throws InvalidIdentifierException If any segment is invalid
     */
    private function validateAndJoinSegments(array $segments): string
    {
        if (empty($segments)) {
            throw new InvalidIdentifierException('Identifier cannot be empty');
        }

        $filteredSegments = array_filter($segments, fn($segment) => $segment !== '');

        if (count($filteredSegments) !== count($segments)) {
            throw new InvalidIdentifierException('Identifier segments cannot be empty');
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
     * @param string $identifier The identifier to check
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
        if (preg_match('/[;\'"\\\\\\-\\-\\/\\*]/', $identifier)) {
            return false;
        }

        // Basic alphanumeric and underscore check with dots for schema.table notation
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', $identifier);
    }

    /**
     * Quote a segment for a specific driver
     *
     * @param string $segment The segment to quote
     * @param string $driver The database driver
     * @return string The quoted segment
     */
    private function quoteSegment(string $segment, string $driver): string
    {
        return match ($driver) {
            'mysql' => "`{$segment}`",
            default => "\"{$segment}\"", // sqlite, pgsql, and others
        };
    }
}