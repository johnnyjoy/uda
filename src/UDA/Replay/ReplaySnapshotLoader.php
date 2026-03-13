<?php

declare(strict_types=1);

namespace UDA\Replay;

use RuntimeException;
use UDA\Tracing\ReplaySnapshot;

final class ReplaySnapshotLoader
{
    /**
     * @return iterable<int,ReplaySnapshot>
     */
    public static function fromFile(string $path): iterable
    {
        if (!is_file($path)) {
            throw new RuntimeException('Replay file not found: ' . $path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open replay file: ' . $path);
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    continue;
                }

                yield ReplaySnapshot::fromArray($decoded);
            }
        } finally {
            fclose($handle);
        }
    }
}
