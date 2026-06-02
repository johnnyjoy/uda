<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

/*
 * Purpose: Configure PHP-CS-Fixer so it mirrors the formatting expectations set
 * in docs/style-guide.md and the rest of the UDA documentation directory.
 *
 * We stick with PSR-12 as the baseline and then layer rules that reinforce the
 * specifics called out in the Style Guide:
 * - strict types in every file ("Named Parameters Only" + general discipline).
 * - consistent docblocks / comments for purpose statements.
 * - clean namespaces/imports to keep the "simple nouns" naming rule intact.
 */

$finderClass = 'PhpCsFixer\\Finder';
$finder = $finderClass::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->notPath('vendor')
    ->name('*.php')
    ->ignoreDotFiles(false)
    ->ignoreVCS(true);

$configClass = 'PhpCsFixer\\Config';

return (new $configClass())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => [
            'statements' => ['return', 'try', 'throw', 'if', 'for', 'foreach', 'while', 'switch'],
        ],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_summary' => false,
        'no_unused_imports' => true,
        'single_import_per_statement' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_line_comment_style' => ['comment_types' => ['hash']],
        'class_definition' => [
            'multi_line_extends_each_single_line' => true,
            'single_item_single_line' => true,
        ],
    ])
    ->setFinder($finder);
