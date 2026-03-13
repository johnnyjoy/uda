<?php

declare(strict_types=1);

namespace UDA\Metrics;

final class SqlNormalizer
{
    /**
     * @return array{fingerprint:string,sql:string}
     */
    public function normalize(string $sql, ?string $fingerprint = null): array
    {
        $trimmed = trim($sql);
        $fingerprint ??= sha1($trimmed);

        return [
            'fingerprint' => $fingerprint,
            'sql' => $trimmed,
        ];
    }
}
