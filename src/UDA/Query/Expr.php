<?php

declare(strict_types=1);

/**
 * @license MIT
 */

namespace UDA\Query;

use UDA\Exception\QueryException;
use UDA\SQL\Identifier;
use UDA\SQL\ParamBag;
use UDA\SQL\Value;

/*
 * Purpose: Represents safe SQL expressions for query builders.
 *
 * Expr carries raw SQL fragments with named parameters, aliases, and optional
 * window metadata. It helps builders compose expressions without owning
 * execution or connection state.
 */

/**
 * Immutable SQL expression value used by query builders.
 */
final class Expr
{
    private readonly string $sql;

    /**
     * @var array<string,mixed>
     */
    private array $params;

    private readonly ?string $alias;

    private ?ExprWindowDefinition $window = null;

    /**
     * Create the runtime object.
     *
     * @param string  $sql     SQL string, SQL message, or builder SQL object.
     * @param array   $params  Named parameter values.
     * @param ?string $alias   SQL alias.
     */
    public function __construct(string $sql, array $params = [], ?string $alias = null)
    {
        $this->sql = $sql;
        $this->alias = $alias;
        $this->params = $this->normalizeParams($params);

        if ($alias !== null) {
            $this->validateAlias($alias);
        }
    }

    /**
     * Raw.
     *
     * @param string $sql     SQL string, SQL message, or builder SQL object.
     * @param array  $params  Named parameter values.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public static function raw(string $sql, array $params = []): self
    {
        if (str_contains($sql, '?')) {
            throw new QueryException('Expr::raw() does not allow positional placeholders.');
        }

        return new self($sql, $params);
    }

    /**
     *   clone.
     *
     * @return mixed Execution result.
     */
    public function __clone()
    {
        if ($this->window !== null) {
            $this->window = clone $this->window;
        }
    }

    /**
     * Row number.
     *
     * @return self Configured instance.
     */
    public static function rowNumber(): self
    {
        return new self('ROW_NUMBER()');
    }

    /**
     * Rank.
     *
     * @return self Configured instance.
     */
    public static function rank(): self
    {
        return new self('RANK()');
    }

    /**
     * Dense rank.
     *
     * @return self Configured instance.
     */
    public static function denseRank(): self
    {
        return new self('DENSE_RANK()');
    }

    /**
     * Lag.
     *
     * @param string|self $value  Value to render or bind.
     *
     * @return self Configured instance.
     */
    public static function lag(string|self $value): self
    {
        [$argument, $params] = self::normalizeValueArgument($value);

        return new self(sprintf('LAG(%s)', $argument), $params);
    }

    /**
     * Lead.
     *
     * @param string|self $value  Value to render or bind.
     *
     * @return self Configured instance.
     */
    public static function lead(string|self $value): self
    {
        [$argument, $params] = self::normalizeValueArgument($value);

        return new self(sprintf('LEAD(%s)', $argument), $params);
    }

    /**
     * Count.
     *
     * @param string|self $target  Target expression.
     *
     * @return self Configured instance.
     */
    public static function count(string|self $target = '*'): self
    {
        [$argument, $params] = self::normalizeValueArgument($target);

        return new self(sprintf('COUNT(%s)', $argument), $params);
    }

    /**
     * Sum.
     *
     * @param string|self $target  Target expression.
     *
     * @return self Configured instance.
     */
    public static function sum(string|self $target): self
    {
        [$argument, $params] = self::normalizeValueArgument($target);

        return new self(sprintf('SUM(%s)', $argument), $params);
    }

    /**
     * Avg.
     *
     * @param string|self $target  Target expression.
     *
     * @return self Configured instance.
     */
    public static function avg(string|self $target): self
    {
        [$argument, $params] = self::normalizeValueArgument($target);

        return new self(sprintf('AVG(%s)', $argument), $params);
    }

    /**
     * Min.
     *
     * @param string|self $target  Target expression.
     *
     * @return self Configured instance.
     */
    public static function min(string|self $target): self
    {
        [$argument, $params] = self::normalizeValueArgument($target);

        return new self(sprintf('MIN(%s)', $argument), $params);
    }

    /**
     * Max.
     *
     * @param string|self $target  Target expression.
     *
     * @return self Configured instance.
     */
    public static function max(string|self $target): self
    {
        [$argument, $params] = self::normalizeValueArgument($target);

        return new self(sprintf('MAX(%s)', $argument), $params);
    }

    /**
     * Coalesce.
     *
     * @param string|self ...$values  Values to process.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public static function coalesce(string|self ...$values): self
    {
        if ($values === []) {
            throw new QueryException('COALESCE requires at least one value.');
        }

        $parts = [];
        $params = [];
        foreach ($values as $index => $value) {
            if ($value instanceof self) {
                $parts[] = $value->sql;
                $params = array_merge($params, $value->params);
            } elseif ($index === 0) {
                $parts[] = $value;
            } else {
                $parts[] = self::quoteLiteral($value);
            }
        }

        return new self('COALESCE(' . implode(', ', $parts) . ')', $params);
    }

    /**
     * As.
     *
     * @param string $alias  SQL alias.
     *
     * @return self Configured instance.
     */
    public function as(string $alias): self
    {
        $this->validateAlias($alias);

        $clone = new self($this->sql, $this->params, $alias);
        if ($this->window !== null) {
            $clone->window = clone $this->window;
        }

        return $clone;
    }

    /**
     * Over.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function over(): self
    {
        if ($this->window !== null) {
            throw new QueryException('Window already defined for this expression.');
        }

        $clone = clone $this;
        $clone->window = ExprWindowDefinition::create();

        return $clone;
    }

    /**
     * Partition by.
     *
     * @param string|self ...$columns  Partition expressions
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function partitionBy(string|self ...$columns): self
    {
        if ($columns === []) {
            throw new QueryException('partitionBy() requires at least one expression.');
        }

        $clone = clone $this;
        $clone->ensureWindow('partitionBy');
        $clone->window = $clone->window->withPartitions($columns);

        return $clone;
    }

    /**
     * Order by.
     *
     * @param string|self $expression  Expression to order by
     * @param string      $direction   Sort direction.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function orderBy(string|self $expression, string $direction = 'ASC'): self
    {
        $clone = clone $this;
        $clone->ensureWindow('orderBy');
        $direction = strtoupper($direction);

        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new QueryException('Window ORDER BY direction must be ASC or DESC');
        }

        $clone->window = $clone->window->withOrder($expression, $direction);

        return $clone;
    }

    /**
     * Rows between.
     *
     * @param string $start  Frame start boundary.
     * @param string $end    Frame end boundary.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function rowsBetween(string $start, string $end): self
    {
        if (trim($start) === '' || trim($end) === '') {
            throw new QueryException('rowsBetween() requires non-empty boundaries.');
        }

        $clone = clone $this;
        $clone->ensureWindow('rowsBetween');
        $clone->window = $clone->window->withFrame(sprintf('ROWS BETWEEN %s AND %s', $start, $end));

        return $clone;
    }

    /**
     * Rows unbounded preceding.
     *
     * @return self Configured instance.
     */
    public function rowsUnboundedPreceding(): self
    {
        return $this->rowsBetween('UNBOUNDED PRECEDING', 'CURRENT ROW');
    }

    /**
     * Rows between unbounded preceding.
     *
     * @return self Configured instance.
     */
    public function rowsBetweenUnboundedPreceding(): self
    {
        return $this->rowsBetween('UNBOUNDED PRECEDING', 'CURRENT ROW');
    }

    /**
     * Rows current row.
     *
     * @return self Configured instance.
     */
    public function rowsCurrentRow(): self
    {
        $clone = clone $this;
        $clone->ensureWindow('rowsCurrentRow');
        $clone->window = $clone->window->withFrame('ROWS CURRENT ROW');

        return $clone;
    }

    /**
     * Range between.
     *
     * @param string $start  Frame start boundary.
     * @param string $end    Frame end boundary.
     *
     * @return self Configured instance.
     *
     * @throws QueryException If the operation fails.
     */
    public function rangeBetween(string $start, string $end): self
    {
        if (trim($start) === '' || trim($end) === '') {
            throw new QueryException('rangeBetween() requires non-empty boundaries.');
        }

        $clone = clone $this;
        $clone->ensureWindow('rangeBetween');
        $clone->window = $clone->window->withFrame(sprintf('RANGE BETWEEN %s AND %s', $start, $end));

        return $clone;
    }

    /**
     * Range between unbounded preceding.
     *
     * @return self Configured instance.
     */
    public function rangeBetweenUnboundedPreceding(): self
    {
        return $this->rangeBetween('UNBOUNDED PRECEDING', 'CURRENT ROW');
    }

    /**
     * Range current row.
     *
     * @return self Configured instance.
     */
    public function rangeCurrentRow(): self
    {
        $clone = clone $this;
        $clone->ensureWindow('rangeCurrentRow');
        $clone->window = $clone->window->withFrame('RANGE CURRENT ROW');

        return $clone;
    }

    /**
     * Return sql.
     *
     * @param ParamBag $params        Named parameter values.
     * @param bool     $includeAlias  Whether to include the expression alias.
     * @param string   $engine        Configured engine key for alias quoting.
     *
     * @return string String result.
     */
    public function getSql(ParamBag $params, bool $includeAlias = true, string $engine = ''): string
    {
        $sql = $this->sql;

        if ($this->params !== []) {
            $replacements = [];

            foreach ($this->params as $token => $value) {
                $replacements[$token] = Value::param($params, $value);
            }

            $sql = strtr($sql, $replacements);
        }

        if ($this->window !== null) {
            $definition = $this->window->render($params);
            $sql .= ' OVER (' . $definition . ')';
        }

        if ($includeAlias && $this->alias !== null) {
            $sql .= ' AS ' . (new Identifier($this->alias))->quoted($engine);
        }

        return $sql;
    }

    /**
     * Uses window.
     *
     * @return bool Boolean result.
     */
    public function usesWindow(): bool
    {
        return $this->window !== null;
    }

    /**
     * Fingerprint sql.
     *
     * @return string String result.
     */
    public function fingerprintSql(): string
    {
        $params = new ParamBag('fp');

        return $this->getSql($params);
    }

    /**
     * Normalize params.
     *
     * @param array $params  Named parameter values.
     *
     * @return array Result array.
     *
     * @throws QueryException If the operation fails.
     */
    private function normalizeParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new QueryException('Expr parameters must use named placeholders (e.g., :name).');
            }
            $token = str_starts_with($key, ':') ? $key : ':' . $key;
            $normalized[$token] = $value;
        }

        return $normalized;
    }

    /**
     * Validate alias.
     *
     * @param string $alias  SQL alias.
     *
     * @return void No return value.
     *
     * @throws QueryException If the operation fails.
     */
    private function validateAlias(string $alias): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
            throw new QueryException('Invalid alias for expression: ' . $alias);
        }
    }

    /**
     * Ensure window.
     *
     * @param string $method  Expression helper method name.
     *
     * @return void No return value.
     *
     * @throws QueryException If the operation fails.
     */
    private function ensureWindow(string $method): void
    {
        if ($this->window === null) {
            throw new QueryException(sprintf('%s() requires an existing window. Call over() first.', $method));
        }
    }

    /**
     * Normalize value argument.
     *
     * @param string|self $value  Value to render or bind.
     *
     * @return array{0:string,1:array<string,mixed>}
     *
     * @throws QueryException If the operation fails.
     */
    private static function normalizeValueArgument(string|self $value): array
    {
        if ($value instanceof self) {
            if ($value->window !== null) {
                throw new QueryException('Nested window expressions are not supported.');
            }

            return [$value->sql, $value->params];
        }

        return [$value, []];
    }

    /**
     * Quote literal.
     *
     * @param string $value  Value to render or bind.
     *
     * @return string String result.
     */
    private static function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}

final class ExprWindowDefinition
{
    /**
     * Create the runtime object.
     *
     * @param array<int,string|Expr>                                    $partitions
     * @param array<int,array{expression:string|Expr,direction:string}> $orderings
     * @param ?string                                                   $frame       Window frame clause.
     */
    private function __construct(
        private array $partitions = [],
        private array $orderings = [],
        private ?string $frame = null
    ) {
    }

    /**
     *   clone.
     *
     * @return mixed Execution result.
     */
    public function __clone()
    {
        // Nothing to deep clone – Expr instances are immutable
    }

    /**
     * Create.
     *
     * @return self Configured instance.
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * With partitions.
     *
     * @param array<int,string|Expr> $columns
     *
     * @return self Configured instance.
     */
    public function withPartitions(array $columns): self
    {
        $clone = clone $this;
        foreach ($columns as $column) {
            $clone->partitions[] = $column;
        }

        return $clone;
    }

    /**
     * With order.
     *
     * @param string|Expr $expression  SQL expression.
     * @param string      $direction   Sort direction.
     *
     * @return self Configured instance.
     */
    public function withOrder(string|Expr $expression, string $direction): self
    {
        $clone = clone $this;
        $clone->orderings[] = [
            'expression' => $expression,
            'direction' => $direction,
        ];

        return $clone;
    }

    /**
     * With frame.
     *
     * @param string $frame  Window frame clause.
     *
     * @return self Configured instance.
     */
    public function withFrame(string $frame): self
    {
        $clone = clone $this;
        $clone->frame = $frame;

        return $clone;
    }

    /**
     * Render.
     *
     * @param ParamBag $params  Named parameter values.
     *
     * @return string String result.
     */
    public function render(ParamBag $params): string
    {
        $parts = [];

        if ($this->partitions !== []) {
            $parts[] = 'PARTITION BY ' . $this->renderExpressions($this->partitions, $params);
        }

        if ($this->orderings !== []) {
            $parts[] = 'ORDER BY ' . $this->renderOrderings($params);
        }

        if ($this->frame !== null) {
            $parts[] = $this->frame;
        }

        return implode(' ', $parts);
    }

    /**
     * Render expressions.
     *
     * @param array<int,string|Expr> $expressions
     * @param ParamBag               $params       Named parameter values.
     *
     * @return string String result.
     */
    private function renderExpressions(array $expressions, ParamBag $params): string
    {
        $rendered = [];

        foreach ($expressions as $expression) {
            $rendered[] = $expression instanceof Expr
                ? $expression->getSql($params, includeAlias: false)
                : $expression;
        }

        return implode(', ', $rendered);
    }

    /**
     * Render orderings.
     *
     * @param ParamBag $params  Named parameter values.
     *
     * @return string String result.
     */
    private function renderOrderings(ParamBag $params): string
    {
        $rendered = [];

        foreach ($this->orderings as $ordering) {
            $expression = $ordering['expression'] instanceof Expr
                ? $ordering['expression']->getSql($params, includeAlias: false)
                : $ordering['expression'];

            $rendered[] = sprintf('%s %s', $expression, $ordering['direction']);
        }

        return implode(', ', $rendered);
    }
}
