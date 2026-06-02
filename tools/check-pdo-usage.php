<?php
/**
 * Purpose: CI tool to enforce PDO usage (PDO/PDOStatement/prepare/execute) restricted to Driver domain.
 *
 * Fails if any of these appear outside `src/UDA/Driver/`:
 * - PDO/PDOStatement class references
 * - ->prepare() or ->execute() calls
 * - new PDO() instantiation
 *
 * Produces human-readable error messages with filename and violating line.
 */

$errors = [];
$srcDir = realpath(__DIR__ . '/../src');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $relativePath = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));

    if ($relativePath === 'UDA/Driver.php' || str_starts_with($relativePath, 'UDA/Driver/')) {
        continue;
    }

    $lines = file($path);
    foreach ($lines as $lineNum => $line) {
        if (preg_match('/\\?PDO(Statement)?\\?|new PDO\(|->prepare\(|->execute\(/', $line)) {
            $errors[] = "Line " . ($lineNum + 1) . ": PDO usage outside Driver domain: {$relativePath}";
            break;
        }
    }
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    fwrite(STDERR, "\nPDO must only appear in src/UDA/Driver/*.\n");
    exit(1);
}

echo "✅ PDO usage is correctly restricted to Driver domain.\n";