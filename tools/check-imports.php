<?php

declare(strict_types=1);

/**
 * Purpose: CI guard for the domain takeover invariants.
 *
 * A domain "takeover" is one domain performing another domain's core function directly
 * (not merely being aware of, or routing through, another domain's ingress). The guard
 * enforces the boundaries that the architecture depends on:
 *
 *   - Query never executes:        UDA\Query\*  must not reference PDO.
 *   - Query never reaches Cache:    UDA\Query\*  must not reference UDA\Cache.
 *   - Cache never executes:         UDA\Cache(.php|\*) must not reference PDO or UDA\Driver.
 *   - Driver never owns a backend:  UDA\Driver(.php|\*) must not reference Redis/Memcached/Predis.
 *
 * Detection is token-based: only real code identifiers are inspected, so references that
 * appear solely in comments, docblocks, or string literals are correctly ignored.
 *
 * Produces human-readable errors with filename and the violating reference; exits non-zero
 * on any violation.
 */

$srcDir = realpath(__DIR__ . '/../src');

if ($srcDir === false) {
    fwrite(STDERR, "ERROR: unable to resolve src/ directory.\n");
    exit(1);
}

/**
 * Collect referenced class/identifier names from PHP source, skipping comments and strings.
 *
 * @return list<string> Names with any leading backslash stripped.
 */
function referenced_names(string $code): array
{
    $names = [];

    foreach (token_get_all($code) as $token) {
        if (!is_array($token)) {
            continue;
        }

        if (
            $token[0] === T_STRING
            || $token[0] === T_NAME_QUALIFIED
            || $token[0] === T_NAME_FULLY_QUALIFIED
        ) {
            $names[] = ltrim($token[1], '\\');
        }
    }

    return $names;
}

/**
 * True when any referenced name resolves to the given base name, either bare
 * (e.g. "PDO", "Redis") or as a namespace segment (e.g. "UDA\Driver\Firebird").
 *
 * @param list<string> $names
 */
function references(array $names, string $base): bool
{
    foreach ($names as $name) {
        if ($name === $base) {
            return true;
        }

        if (in_array($base, explode('\\', $name), true)) {
            return true;
        }
    }

    return false;
}

$errors = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));

    $isQuery  = str_starts_with($relative, 'UDA/Query/');
    $isCache  = $relative === 'UDA/Cache.php' || str_starts_with($relative, 'UDA/Cache/');
    $isDriver = $relative === 'UDA/Driver.php' || str_starts_with($relative, 'UDA/Driver/');

    if (!$isQuery && !$isCache && !$isDriver) {
        continue;
    }

    $code  = (string) file_get_contents($path);
    $names = referenced_names($code);

    if ($isQuery && references($names, 'PDO')) {
        $errors[] = "Query->PDO takeover: {$relative} executes via PDO (execution belongs to Driver).";
    }

    if ($isQuery && references($names, 'Cache')) {
        $errors[] = "Query->Cache takeover: {$relative} reaches into the Cache domain directly.";
    }

    if ($isCache && references($names, 'PDO')) {
        $errors[] = "Cache->PDO takeover: {$relative} executes via PDO (execution belongs to Driver).";
    }

    if ($isCache && references($names, 'Driver')) {
        $errors[] = "Cache->Driver takeover: {$relative} drives the database directly.";
    }

    if ($isDriver) {
        foreach (['Redis', 'RedisCluster', 'Memcached', 'Predis'] as $backend) {
            if (references($names, $backend)) {
                $errors[] = "Driver->{$backend} takeover: {$relative} owns a cache backend directly (route through Cache).";
            }
        }
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    fwrite(STDERR, "\nDomain takeover detected. Cross-domain work must route through the owning domain's ingress.\n");
    exit(1);
}

echo "No domain takeovers found.\n";
