<?php

declare(strict_types=1);

/**
 * Function call builder with security whitelist validation.
 *
 * This class provides secure SQL function call construction by validating
 * function names against a predefined whitelist of safe SQL functions.
 * It prevents injection of dangerous database functions while allowing
 * common aggregate, string, date, and mathematical functions.
 *
 * PURPOSE: To enable safe construction of SQL function calls in queries
 *          by validating function names against known-safe functions and
 *          explicitly blocking dangerous operations that could compromise
 *          database security or integrity.
 */

namespace UDA\SQL;

/**
 * Function call with name whitelist validation
 */
class FunctionCall
{
    private string $expression;

    // Whitelisted SQL functions
    private const WHITELIST = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
        'UPPER', 'LOWER', 'LENGTH', 'SUBSTR', 'TRIM',
        'CONCAT', 'REPLACE', 'POSITION', 'COALESCE',
        'ABS', 'ROUND', 'CEIL', 'FLOOR', 'MOD',
        'NOW', 'DATE', 'TIME', 'YEAR', 'MONTH', 'DAY',
        'CAST', 'EXTRACT', 'CASE', 'WHEN', 'THEN', 'ELSE', 'END'
    ];

    // Dangerous functions that should never be allowed
    private const DANGEROUS = [
        'EXEC', 'EXECUTE', 'SYSTEM', 'SHELL'
    ];

    /**
     * Create a new function call
     *
     * @param string $function The function name
     * @param string ...$arguments The function arguments
     * @throws InvalidFunctionException If the function is invalid or dangerous
     */
    public function __construct(string $function, string ...$arguments)
    {
        $upperFunction = strtoupper($function);

        if (in_array($upperFunction, self::DANGEROUS, true)) {
            throw new InvalidFunctionException("Dangerous function not allowed: {$upperFunction}");
        }

        if (!in_array($upperFunction, self::WHITELIST, true)) {
            throw new InvalidFunctionException("Invalid function: {$upperFunction}");
        }

        $this->expression = $upperFunction . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * Get the function expression
     *
     * @return string
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Convert to string representation
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->expression;
    }
}