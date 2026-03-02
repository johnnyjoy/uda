<?php

declare(strict_types=1);

/**
 * SQL keyword token with security whitelist validation.
 *
 * This class validates SQL keywords against a predefined whitelist of safe
 * keywords and explicitly blocks dangerous operations like DROP, TRUNCATE,
 * and EXECUTE. It ensures that only permitted SQL keywords can be used
 * in query construction, preventing injection of destructive operations.
 *
 * PURPOSE: To provide a secure representation of SQL keywords that prevents
 *          dangerous database operations from being injected through keyword
 *          manipulation while allowing safe query construction with validated
 *          keywords.
 */

namespace UDA\SQL;

/**
 * Keyword token with whitelist validation
 */
class Keyword
{
    private string $token;

    // Whitelisted SQL keywords
    private const WHITELIST = [
        'SELECT', 'FROM', 'WHERE', 'ORDER', 'BY', 'LIMIT', 'OFFSET',
        'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE',
        'JOIN', 'INNER', 'LEFT', 'RIGHT', 'OUTER', 'ON',
        'GROUP', 'HAVING', 'ASC', 'DESC', 'AS', 'AND', 'OR',
        'IN', 'NOT', 'NULL', 'IS', 'LIKE', 'BETWEEN', 'EXISTS',
        'DISTINCT', 'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
        'CREATE', 'TABLE', 'INDEX', 'PRIMARY', 'KEY', 'FOREIGN',
        'REFERENCES', 'CONSTRAINT', 'UNIQUE', 'DEFAULT',
        'BEGIN', 'COMMIT', 'ROLLBACK', 'TRANSACTION'
    ];

    // Dangerous keywords that should never be allowed
    private const DANGEROUS = [
        'DROP', 'TRUNCATE', 'ALTER', 'EXEC', 'EXECUTE'
    ];

    /**
     * Create a new keyword token
     *
     * @param string $keyword The keyword
     * @throws InvalidKeywordException If the keyword is invalid or dangerous
     */
    public function __construct(string $keyword)
    {
        $upperKeyword = strtoupper($keyword);

        if (in_array($upperKeyword, self::DANGEROUS, true)) {
            throw new InvalidKeywordException("Dangerous keyword not allowed: {$upperKeyword}");
        }

        if (!in_array($upperKeyword, self::WHITELIST, true)) {
            throw new InvalidKeywordException("Invalid keyword: {$upperKeyword}");
        }

        $this->token = $upperKeyword;
    }

    /**
     * Get the keyword token
     *
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->token;
    }
}