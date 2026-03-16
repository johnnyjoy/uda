<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache
 */

namespace UDA\Cache;

/*
 * Purpose: Immutable descriptor containing cache store, policy, and tracker configuration for a connection.
 */

use UDA\Cache\Serializer\Serializer;
use UDA\Cache\Store\CacheStoreInterface;

/**
 * Cache setup descriptor passed from configuration into the runtime cache controller.
 */
final class Setup
{
    public function __construct(
        private CacheStoreInterface $store,
        private TableWriteTrackerInterface $tracker,
        private Serializer $serializer,
        private string $namespace,
        private array $defaultPolicy,
        private array $tablePolicies,
        private int $formatVersion = 1
    ) {
    }

    public function store(): CacheStoreInterface
    {
        return $this->store;
    }

    public function tracker(): TableWriteTrackerInterface
    {
        return $this->tracker;
    }

    public function serializer(): Serializer
    {
        return $this->serializer;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * @return array{
     *   ttlSeconds:int,
     *   minIntervalSeconds:int,
     *   allowStaleOnError:bool,
     *   maxStaleSeconds:int,
     *   disabled?:bool
     * }
     */
    public function defaultPolicy(): array
    {
        return $this->defaultPolicy;
    }

    /**
     * @return array<string, array>
     */
    public function tablePolicies(): array
    {
        return $this->tablePolicies;
    }

    public function formatVersion(): int
    {
        return $this->formatVersion;
    }
}
