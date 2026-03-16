<?php

declare(strict_types=1);

/**
 * @package UDA
 * @subpackage Cache\Serializer
 */

namespace UDA\Cache\Serializer;

/*
 * Purpose: Provides igbinary-or-PHP serialization for cached payloads with explicit format identifiers.
 */
/**
 * Serializer helper that prefers igbinary when available.
 */
final class Serializer
{
    private const FORMAT_IGBINARY = 'igb';
    private const FORMAT_PHP = 'php';

    private string $format;

    public function __construct(?string $preferred = null)
    {
        $preferred = $preferred !== null ? strtolower($preferred) : null;

        if ($preferred === 'igbinary' && extension_loaded('igbinary')) {
            $this->format = self::FORMAT_IGBINARY;

            return;
        }

        if ($preferred === 'php' || $preferred === null) {
            $this->format = self::FORMAT_PHP;

            return;
        }

        if ($preferred !== null && $preferred !== 'igbinary' && $preferred !== 'php') {
            // Unknown preference – fall through to availability checks
        }

        $this->format = extension_loaded('igbinary') ? self::FORMAT_IGBINARY : self::FORMAT_PHP;
    }

    public function id(): string
    {
        return $this->format;
    }

    public function encode(mixed $value): string
    {
        if ($this->format === self::FORMAT_IGBINARY) {
            return (string) \call_user_func('igbinary_serialize', $value);
        }

        return serialize($value);
    }

    public function decode(string $payload): mixed
    {
        if ($this->format === self::FORMAT_IGBINARY) {
            return \call_user_func('igbinary_unserialize', $payload);
        }

        return unserialize($payload);
    }
}
