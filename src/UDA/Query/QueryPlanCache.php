<?php

declare(strict_types=1);

namespace UDA\Query;

use UDA\SQL\SqlMessage;

/**
 * Process-wide cache for compiled query plans (SqlMessage instances).
 */
final class QueryPlanCache
{
    private const DEFAULT_LIMIT = 1000;

    /** @var array<string,SqlMessage> */
    private static array $plans = [];

    /** @var list<string> */
    private static array $order = [];

    private static int $limit = self::DEFAULT_LIMIT;

    private static bool $enabled = true;

    private function __construct()
    {
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function clear(): void
    {
        self::$plans = [];
        self::$order = [];
    }

    public static function setLimit(int $limit): void
    {
        self::$limit = max(1, $limit);
        self::enforceLimit();
    }

    public static function size(): int
    {
        return count(self::$plans);
    }

    public static function get(string $key): ?SqlMessage
    {
        if (!self::$enabled) {
            return null;
        }

        if (!isset(self::$plans[$key])) {
            return null;
        }

        return self::cloneMessage(self::$plans[$key]);
    }

    public static function put(string $key, SqlMessage $message): void
    {
        if (!self::$enabled) {
            return;
        }

        if (isset(self::$plans[$key])) {
            self::$plans[$key] = self::cloneMessage($message);
            self::promoteKey($key);
        } else {
            self::$plans[$key] = self::cloneMessage($message);
            self::$order[] = $key;
            self::enforceLimit();
        }
    }

    private static function enforceLimit(): void
    {
        while (count(self::$plans) > self::$limit && self::$order !== []) {
            $evict = array_shift(self::$order);

            if ($evict !== null) {
                unset(self::$plans[$evict]);
            }
        }
    }

    private static function promoteKey(string $key): void
    {
        $index = array_search($key, self::$order, true);

        if ($index === false) {
            self::$order[] = $key;

            return;
        }

        unset(self::$order[$index]);
        self::$order[] = $key;
        self::$order = array_values(self::$order);
    }

    private static function cloneMessage(SqlMessage $message): SqlMessage
    {
        return new SqlMessage(
            $message->getQuery(),
            $message->getParams(),
            $message->getCacheTables(),
            $message->getReturningColumns(),
            $message->getInsertTable(),
            $message->getInsertColumns(),
            $message->getValuePlaceholders(),
            $message->getStatementType(),
            $message->hasWhereClause(),
            $message->hasLimitClause(),
            $message->isUnsafe()
        );
    }
}
