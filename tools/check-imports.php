<?php
/**
 * Purpose: CI tool to enforce forbidden import boundaries (Query→Cache, Cache→Driver, Query→PDO).
 *
 * Fails if:
 * - Query/ imports Cache/
 * - Cache/ imports Driver/
 * - Query/ imports PDO
 *
 * Produces human-readable errors with filename and violating import.
 */

$errors = [];
$srcDir = realpath(__DIR__ . '/../src');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $lines = file($path);
    $relativePath = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
    
    foreach ($lines as $lineNum => $line) {
        if (strpos(trim($line), 'use ') === 0) {
            // Query imports Cache
            if (strpos($relativePath, 'Query/') === 0 && preg_match('/use.*Cache/', $line)) {
                $errors[] = "Line " . ($lineNum + 1) . ": Query→Cache forbidden import in {$relativePath}";
            }
            // Cache imports Driver
            if (strpos($relativePath, 'Cache/') === 0 && preg_match('/use.*Driver/', $line)) {
                $errors[] = "Line " . ($lineNum + 1) . ": Cache→Driver forbidden import in {$relativePath}";
            }
            // Query imports PDO
            if (strpos($relativePath, 'Query/') === 0 && preg_match('/use.*PDO/', $line)) {
                $errors[] = "Line " . ($lineNum + 1) . ": Query→PDO forbidden import in {$relativePath}";
            }
        }
    }
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    fwrite(STDERR, "\nForbidden imports detected. Respect architectural boundaries.\n");
    exit(1);
}

echo "✅ No forbidden imports found.\n";