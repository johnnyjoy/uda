<?php
/**
 * Purpose: CI tool to forbid class suffixes (Manager/Service/Engine/Facade/Handler/Controller).
 *
 * Fails if any class name ends with forbidden architectural patterns.
 * Produces human-readable errors with filename and violating class name.
 */

$errors = [];
$forbiddenSuffixes = ['Manager', 'Service', 'Engine', 'Facade', 'Handler', 'Controller'];
$srcDir = realpath(__DIR__ . '/../src');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $lines = file($path);
    $filename = str_replace('\\', '/', substr($path, strlen($srcDir) + 1));
    
    foreach ($lines as $lineNum => $line) {
        if (preg_match('/^class\s+(\w+)/', $line, $matches)) {
            $className = $matches[1];
            foreach ($forbiddenSuffixes as $suffix) {
                if (substr($className, -strlen($suffix)) === $suffix) {
                    $errors[] = "Line " . ($lineNum + 1) . ": Forbidden suffix '{$suffix}' in class '{$className}' ({$filename})";
                }
            }
        }
    }
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }
    fwrite(STDERR, "\nAvoid class names ending in Manager/Service/Engine/Facade/Handler/Controller.\n");
    exit(1);
}

echo "✅ No forbidden class suffixes found.\n";