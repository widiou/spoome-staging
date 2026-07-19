<?php

/**
 * Config php-cs-fixer per Spoome.
 *
 * Copre il codice del progetto (src, tests, database, config, i .php di public)
 * escludendo vendor, node_modules, tmp, .team, .claude.
 *
 * Ruleset di partenza: @PSR12. setRiskyAllowed(false) per sicurezza: nessuna
 * regola risky (comportamento runtime) è ammessa in questa fase, solo fix
 * puramente stilistici.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->path([
        'src',
        'tests',
        'database',
        'config',
    ])
    ->name('*.php')
    ->append([
        __DIR__ . '/public/index.php',
    ])
    ->exclude([
        'vendor',
        'node_modules',
        'tmp',
        '.team',
        '.claude',
    ])
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
