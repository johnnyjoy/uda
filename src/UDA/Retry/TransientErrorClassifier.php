<?php

declare(strict_types=1);

namespace UDA\Retry;

use PDOException;
use Throwable;
use UDA\Driver;

final class TransientErrorClassifier
{
    /** @var array<string,bool> */
    private const SQLSTATE_MAP = [
        '40001' => true,
        '40P01' => true,
        'HYT00' => true,
        '08006' => true,
        '08003' => true,
        '57P01' => true,
    ];

    /** @var string[] */
    private const MESSAGE_PATTERNS = [
        'deadlock',
        'lock wait timeout',
        'connection reset',
        'server has gone away',
        'connection refused',
    ];

    public function isTransient(Throwable $exception, ?Driver $driver = null): bool
    {
        if ($driver !== null) {
            $driverDecision = $driver->isTransientError($exception);

            if ($driverDecision !== null) {
                return $driverDecision;
            }
        }

        $pdoException = $this->findPdoException($exception);
        if ($pdoException instanceof PDOException) {
            $sqlState = $this->extractSqlState($pdoException);

            if ($sqlState !== null && isset(self::SQLSTATE_MAP[$sqlState])) {
                return true;
            }
        }

        $cursor = $exception;
        while ($cursor !== null) {
            $message = strtolower($cursor->getMessage());
            foreach (self::MESSAGE_PATTERNS as $pattern) {
                if ($message !== '' && str_contains($message, $pattern)) {
                    return true;
                }
            }
            $cursor = $cursor->getPrevious();
        }

        return false;
    }

    private function extractSqlState(PDOException $exception): ?string
    {
        if (is_array($exception->errorInfo) && isset($exception->errorInfo[0])) {
            return strtoupper((string) $exception->errorInfo[0]);
        }

        $code = (string) $exception->getCode();
        if ($code !== '') {
            return strtoupper($code);
        }

        return null;
    }

    private function findPdoException(Throwable $exception): ?PDOException
    {
        $cursor = $exception;

        while ($cursor !== null) {
            if ($cursor instanceof PDOException) {
                return $cursor;
            }

            $cursor = $cursor->getPrevious();
        }

        return null;
    }
}
