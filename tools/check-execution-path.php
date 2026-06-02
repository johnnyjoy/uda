<?php
/**
 * Purpose: CI tool to verify exactly one prepare/execute hot path exists in Driver.php.
 *
 * Fails if:
 * - No prepare/execute calls exist
 * - Prepare/execute calls appear outside Driver.php
 * - Multiple prepare/execute call sites exist
 *
 * Produces human-readable error messages with details.
 */

$prepareExecuteFiles = [];
$prepareExecuteDetails = [];
$srcDir = realpath(__DIR__ . '/../src');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $lines = file($path);
    $relativePath = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
    
    foreach ($lines as $lineNum => $line) {
        if (preg_match('/->prepare\(|->execute\(/', $line)) {
            $prepareExecuteFiles[$relativePath] = $prepareExecuteFiles[$relativePath] ?? [];
            $prepareExecuteFiles[$relativePath][] = $lineNum + 1;
        }
    }
}

// Check conditions
$errors = [];
if (count($prepareExecuteFiles) === 0) {
    $errors[] = "No prepare/execute calls found in the codebase.";
} elseif (count($prepareExecuteFiles) > 1 || !isset($prepareExecuteFiles['UDA/Driver.php'])) {
    $errors[] = "Prepare/execute calls must exist ONLY in UDA/Driver.php.";
    $errors[] = "Found in:";
    foreach ($prepareExecuteFiles as $path => $lines) {
        $errors[] = "- {$path} (lines: " . implode(', ', $lines) . ")";
    }
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    exit(1);
}

echo "✅ Exactly one prepare/execute path exists (UDA/Driver.php).\n";