<?php
/**
 * Purpose: CI tool to ensure every PHP file in src/ declares @license MIT and not GPL.
 */

$errors = [];
$srcDir = realpath(__DIR__ . '/../src');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $lines = file($path);
    $relativePath = substr($path, strlen($srcDir) + 1);
    $header = implode('', array_slice($lines, 0, 40));

    if (preg_match('/@license\s+GPL/i', $header)) {
        $errors[] = "Forbidden GPL license header: {$relativePath}";
    }

    if (!preg_match('/@license\s+MIT\b/i', $header)) {
        $errors[] = "Missing @license MIT in file header: {$relativePath}";
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "All src/ files declare @license MIT.\n";
