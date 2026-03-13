<?php

declare(strict_types=1);

namespace UDA\Tracing;

interface ReplayStorageInterface
{
    public function persist(ReplaySnapshot $snapshot): void;

    public function flush(): void;

    public function close(): void;
}
