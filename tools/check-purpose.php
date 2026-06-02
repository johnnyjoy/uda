<?php
/**
 * Purpose: CI tool to ensure every PHP file in src/ contains a "Purpose:" comment.
 *
 * Enforces the architectural rule that every source file must begin with
 * a clear, machine-verifiable purpose statement. The purpose must appear
 * within the first 40 lines of the file.
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

    // Inspect first 40 lines
    $header = implode('', array_slice($lines, 0, 40));

    if (!preg_match('/\/\*.*Purpose:/s', $header)) {
        $errors[] = "Missing Purpose statement: {$relativePath}";
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . PHP_EOL);
    }
    exit(1);
}

echo "All files contain a Purpose statement.\n";
