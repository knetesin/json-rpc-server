<?php

declare(strict_types=1);

/*
 * PHP-CS-Fixer 3.x configuration.
 *
 * Ruleset is Symfony's house style with a few PHP 8.3-friendly tweaks. Keep
 * the list flat and explicit — every entry should be easy to defend and
 * easy to remove if it ever conflicts with editor formatting.
 *
 * Run `composer cs-check` (or `composer cs-fix`) — the dist tooling does
 * not need any flags.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    // MakerBundle skeletons are template fragments rendered through extract +
    // include. Their leading `<?php echo …` idiom trips up the indentation
    // rules, and they are never executed as PHP source in the bundle itself.
    ->notPath('Resources/skeleton')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        // ---- Base rulesets ----
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP83Migration' => true,
        '@PHPUnit100Migration:risky' => true,

        // ---- Project additions ----
        'declare_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_separation' => false,
        'single_line_throw' => false,
        'yoda_style' => [
            'equal' => true,
            'identical' => true,
            'less_and_greater' => false,
        ],

        // ---- Symfony-default overrides ----
        // Concatenation without spaces reads better for short joins like
        // sprintf('%s/%d', $a, $b).$suffix and is consistent across the codebase.
        'concat_space' => ['spacing' => 'none'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');
